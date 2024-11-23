<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Subscription\Engine;

use Neos\ContentRepository\Core\EventStore\EventInterface;
use Neos\ContentRepository\Core\EventStore\EventNormalizer;
use Neos\ContentRepository\Core\Subscription\Exception\CatchUpFailed;
use Neos\ContentRepository\Core\Subscription\Exception\SubscriptionEngineAlreadyProcessingException;
use Neos\ContentRepository\Core\Subscription\SubscriptionAndProjectionStatus;
use Neos\ContentRepository\Core\Subscription\SubscriptionAndProjectionStatuses;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatusFilter;
use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\EventEnvelope;
use Neos\EventStore\Model\EventStream\VirtualStreamName;
use Psr\Log\LoggerInterface;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\ContentRepository\Core\Subscription\Store\SubscriptionCriteria;
use Neos\ContentRepository\Core\Subscription\Store\SubscriptionStoreInterface;
use Neos\ContentRepository\Core\Subscription\Subscriber\Subscribers;
use Neos\ContentRepository\Core\Subscription\Subscription;
use Neos\ContentRepository\Core\Subscription\Subscriptions;

/**
 * @api
 */
final class SubscriptionEngine
{
    private bool $processing = false;
    private readonly SubscriptionManager $subscriptionManager;

    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly SubscriptionStoreInterface $subscriptionStore,
        private readonly Subscribers $subscribers,
        private readonly EventNormalizer $eventNormalizer,
        private readonly LoggerInterface|null $logger = null,
    ) {
        $this->subscriptionManager = new SubscriptionManager($this->subscriptionStore);
    }

    public function setup(SubscriptionEngineCriteria|null $criteria = null): Result
    {
        $criteria ??= SubscriptionEngineCriteria::noConstraints();

        $this->logger?->info('Subscription Engine: Start to setup.');

        $this->subscriptionStore->setup();
        $this->discoverNewSubscriptions();
        $subscriptions = $this->subscriptionStore->findByCriteria(SubscriptionCriteria::forEngineCriteriaAndStatus($criteria, SubscriptionStatus::NEW));
        if ($subscriptions->isEmpty()) {
            $this->logger?->info('Subscription Engine: No subscriptions to setup, finish setup.');
            return Result::success();
        }
        $errors = [];
        foreach ($subscriptions as $subscription) {
            $error = $this->setupSubscription($subscription);
            if ($error !== null) {
                $errors[] = $error;
            }
        }
        $this->subscriptionManager->flush();
        return $errors === [] ? Result::success() : Result::failed(Errors::fromArray($errors));
    }

    public function boot(SubscriptionEngineCriteria|null $criteria = null, \Closure $progressCallback = null): ProcessedResult
    {
        return $this->processExclusively(fn () => $this->catchUpSubscriptions($criteria ?? SubscriptionEngineCriteria::noConstraints(), SubscriptionStatus::BOOTING, $progressCallback));
    }

    public function catchUpActive(SubscriptionEngineCriteria|null $criteria = null, \Closure $progressCallback = null): ProcessedResult
    {
        return $this->processExclusively(fn () => $this->catchUpSubscriptions($criteria ?? SubscriptionEngineCriteria::noConstraints(), SubscriptionStatus::ACTIVE, $progressCallback));
    }

    public function reset(SubscriptionEngineCriteria|null $criteria = null): Result
    {
        $criteria ??= SubscriptionEngineCriteria::noConstraints();

        $this->logger?->info('Subscription Engine: Start to reset.');
        $subscriptions = $this->subscriptionStore->findByCriteria(SubscriptionCriteria::forEngineCriteriaAndStatus($criteria, SubscriptionStatusFilter::any()));
        if ($subscriptions->isEmpty()) {
            $this->logger?->info('Subscription Engine: No subscriptions to reset.');
            return Result::success();
        }
        $errors = [];
        foreach ($subscriptions as $subscription) {
            $error = $this->resetSubscription($subscription);
            if ($error !== null) {
                $errors[] = $error;
            }
        }
        $this->subscriptionManager->flush();
        return $errors === [] ? Result::success() : Result::failed(Errors::fromArray($errors));
    }

    public function subscriptionStatuses(SubscriptionCriteria|null $criteria = null): SubscriptionAndProjectionStatuses
    {
        $statuses = [];
        foreach ($this->subscriptionStore->findByCriteria($criteria ?? SubscriptionCriteria::noConstraints()) as $subscription) {
            $subscriber = $this->subscribers->contain($subscription->id) ? $this->subscribers->get($subscription->id) : null;
            $statuses[] = SubscriptionAndProjectionStatus::create(
                subscriptionId: $subscription->id,
                subscriptionStatus: $subscription->status,
                subscriptionPosition: $subscription->position,
                subscriptionError: $subscription->error,
                projectionStatus: $subscriber?->projection->status(),
            );
        }
        return SubscriptionAndProjectionStatuses::fromArray($statuses);
    }

    private function handleEvent(EventEnvelope $eventEnvelope, EventInterface $domainEvent, Subscription $subscription): Error|null
    {
        $subscriber = $this->subscribers->get($subscription->id);
        try {
            $subscriber->handle($domainEvent, $eventEnvelope);
        } catch (\Throwable $e) {
            $this->logger?->error(sprintf('Subscription Engine: Subscriber "%s" for "%s" could not process the event "%s" (sequence number: %d): %s', $subscriber::class, $subscription->id->value, $eventEnvelope->event->type->value, $eventEnvelope->sequenceNumber->value, $e->getMessage()));
            $subscription->fail($e);
            $this->subscriptionManager->update($subscription);
            return Error::fromSubscriptionIdAndException($subscription->id, $e);
        }
        $this->logger?->debug(sprintf('Subscription Engine: Subscriber "%s" for "%s" processed the event "%s" (sequence number: %d).', substr(strrchr($subscriber::class, '\\') ?: '', 1), $subscription->id->value, $eventEnvelope->event->type->value, $eventEnvelope->sequenceNumber->value));
        $subscription->set(
            position: $eventEnvelope->sequenceNumber
        );
        return null;
    }

    /**
     * Find all subscribers that don't have a corresponding subscription.
     * For each match a subscription is added
     *
     * Note: newly discovered subscriptions are not ACTIVE by default, instead they have to be initialized via {@see self::setup()} explicitly
     */
    private function discoverNewSubscriptions(): void
    {
        $this->subscriptionManager->findForAndUpdate(
            SubscriptionCriteria::noConstraints(),
            function (Subscriptions $subscriptions) {
                foreach ($this->subscribers as $subscriber) {
                    if ($subscriptions->contain($subscriber->id)) {
                        continue;
                    }
                    $subscription = Subscription::createFromSubscriber($subscriber);
                    $this->subscriptionManager->add($subscription);
                    $this->logger?->info(sprintf('Subscription Engine: New Subscriber "%s" was found and added to the subscription store.', $subscriber->id->value));
                }
            }
        );
    }

    private function discoverDetachedSubscriptions(SubscriptionEngineCriteria $criteria): void
    {
        $registeredSubscriptions = $this->subscriptionStore->findByCriteria(SubscriptionCriteria::create(
            $criteria->ids,
            SubscriptionStatusFilter::fromArray([SubscriptionStatus::ACTIVE]),
        ));
        foreach ($registeredSubscriptions as $subscription) {
            if ($this->subscribers->contain($subscription->id)) {
                continue;
            }
            $subscription->set(
                status: SubscriptionStatus::DETACHED,
            );
            $this->subscriptionManager->update($subscription);
            $this->logger?->info(sprintf('Subscription Engine: Subscriber for "%s" not found and has been marked as detached.', $subscription->id->value));
        }
        // todo use transaction here for discovery as well!!!
        $this->subscriptionManager->flush();
    }


    /**
     * Set up the subscription by retrieving the corresponding subscriber and calling the setUp method on its handler
     * If the setup fails, the subscription will be in the {@see SubscriptionStatus::ERROR} state and a corresponding {@see Error} is returned
     */
    private function setupSubscription(Subscription $subscription): ?Error
    {
        $subscriber = $this->subscribers->get($subscription->id);
        try {
            $subscriber->projection->setUp();
        } catch (\Throwable $e) {
            $this->logger?->error(sprintf('Subscription Engine: Subscriber "%s" for "%s" has an error in the setup method: %s', $subscriber::class, $subscription->id->value, $e->getMessage()));
            $subscription->fail($e);
            $this->subscriptionManager->update($subscription);
            return Error::fromSubscriptionIdAndException($subscription->id, $e);
        }
        $subscription->set(
            status: SubscriptionStatus::BOOTING
        );
        $this->subscriptionManager->update($subscription);
        $this->logger?->debug(sprintf('Subscription Engine: For Subscriber "%s" for "%s" the setup method has been executed, set to %s.', $subscriber::class, $subscription->id->value, $subscription->status->value));
        return null;
    }

    /**
     * TODO
     */
    private function resetSubscription(Subscription $subscription): ?Error
    {
        $subscriber = $this->subscribers->get($subscription->id);
        try {
            $subscriber->projection->resetState();
        } catch (\Throwable $e) {
            $this->logger?->error(sprintf('Subscription Engine: Subscriber "%s" for "%s" has an error in the resetState method: %s', $subscriber::class, $subscription->id->value, $e->getMessage()));
            return Error::fromSubscriptionIdAndException($subscription->id, $e);
        }
        $subscription->reset();
        $this->subscriptionManager->update($subscription);
        $this->logger?->debug(sprintf('Subscription Engine: For Subscriber "%s" for "%s" the resetState method has been executed.', $subscriber::class, $subscription->id->value));
        return null;
    }

    private function catchUpSubscriptions(SubscriptionEngineCriteria $criteria, SubscriptionStatus $subscriptionStatus, \Closure $progressClosure = null): ProcessedResult
    {
        $this->logger?->info(sprintf('Subscription Engine: Start catching up subscriptions in state "%s".', $subscriptionStatus->value));

        $this->discoverNewSubscriptions();
        $this->discoverDetachedSubscriptions($criteria);

        return $this->subscriptionManager->findForAndUpdate(
            SubscriptionCriteria::forEngineCriteriaAndStatus($criteria, $subscriptionStatus),
            function (Subscriptions $subscriptions) use ($subscriptionStatus, $progressClosure) {
                if ($subscriptions->isEmpty()) {
                    $this->logger?->info(sprintf('Subscription Engine: No subscriptions in state "%s". Finishing catch up', $subscriptionStatus->value));

                    return ProcessedResult::success(0);
                }
                foreach ($subscriptions as $subscription) {
                    try {
                        $this->subscribers->get($subscription->id)->onBeforeCatchUp($subscription->status);
                    } catch (\Throwable $e) {
                        // analog to onAfterCatchUp, we tolerate no exceptions here and consider it a critical developer error.
                        $message = sprintf('Subscriber "%s" failed onBeforeCatchUp: %s', $subscription->id->value, $e->getMessage());
                        $this->logger?->critical($message);
                        throw new CatchUpFailed($message, 1732374000, $e);
                    }
                }
                $startSequenceNumber = $subscriptions->lowestPosition()?->next() ?? SequenceNumber::none();
                $this->logger?->debug(sprintf('Subscription Engine: Event stream is processed from position %s.', $startSequenceNumber->value));

                /** @var list<Error> $errors */
                $errors = [];
                $numberOfProcessedEvents = 0;
                try {
                    $eventStream = $this->eventStore->load(VirtualStreamName::all())->withMinimumSequenceNumber($startSequenceNumber);
                    foreach ($eventStream as $eventEnvelope) {
                        $sequenceNumber = $eventEnvelope->sequenceNumber;
                        if ($numberOfProcessedEvents > 0) {
                            $this->logger?->debug(sprintf('Subscription Engine: Current event stream position: %s', $sequenceNumber->value));
                        }
                        if ($progressClosure !== null) {
                            $progressClosure($eventEnvelope);
                        }
                        $domainEvent = $this->eventNormalizer->denormalize($eventEnvelope->event);
                        foreach ($subscriptions as $subscription) {
                            if ($subscription->status !== $subscriptionStatus) {
                                continue;
                            }
                            if ($subscription->position->value >= $sequenceNumber->value) {
                                $this->logger?->debug(sprintf('Subscription Engine: Subscription "%s" is farther than the current position (%d >= %d), continue catch up.', $subscription->id->value, $subscription->position->value, $sequenceNumber->value));
                                continue;
                            }
                            $this->subscriptionStore->createSavepoint();
                            $error = $this->handleEvent($eventEnvelope, $domainEvent, $subscription);
                            if (!$error) {
                                $this->subscriptionStore->releaseSavepoint();
                                continue;
                            }
                            $this->subscriptionStore->rollbackSavepoint();
                            $errors[] = $error;
                        }
                        $numberOfProcessedEvents++;
                    }
                } finally {
                    foreach ($subscriptions as $subscription) {
                        $this->subscriptionManager->update($subscription);
                    }
                }
                foreach ($subscriptions as $subscription) {
                    try {
                        $this->subscribers->get($subscription->id)->onAfterCatchUp();
                    } catch (\Throwable $e) {
                        // analog to onBeforeCatchUp, we tolerate no exceptions here and consider it a critical developer error.
                        $message = sprintf('Subscriber "%s" failed onAfterCatchUp: %s', $subscription->id->value, $e->getMessage());
                        $this->logger?->critical($message);
                        throw new CatchUpFailed($message, 1732374000, $e);
                    }
                    if ($subscription->status !== $subscriptionStatus) {
                        continue;
                    }

                    if ($subscription->status !== SubscriptionStatus::ACTIVE) {
                        $subscription->set(
                            status: SubscriptionStatus::ACTIVE,
                        );
                        $this->subscriptionManager->update($subscription);
                        $this->logger?->info(sprintf('Subscription Engine: Subscription "%s" has been set to active after booting.', $subscription->id->value));
                    }
                }
                $this->logger?->info(sprintf('Subscription Engine: Finish catch up. %d processed events %d errors.', $numberOfProcessedEvents, count($errors)));
                return $errors === [] ? ProcessedResult::success($numberOfProcessedEvents) : ProcessedResult::failed($numberOfProcessedEvents, Errors::fromArray($errors));
            }
        );
    }

    /**
     * @template T
     * @param \Closure(): T $closure
     * @return T
     */
    private function processExclusively(\Closure $closure): mixed
    {
        if ($this->processing) {
            throw new SubscriptionEngineAlreadyProcessingException();
        }
        $this->processing = true;
        try {
            return $closure();
        } finally {
            $this->processing = false;
        }
    }
}
