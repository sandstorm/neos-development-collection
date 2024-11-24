<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Service;

use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceInterface;
use Neos\ContentRepository\Core\Subscription\Engine\Errors;
use Neos\ContentRepository\Core\Subscription\Engine\SubscriptionEngine;
use Neos\ContentRepository\Core\Subscription\Engine\SubscriptionEngineCriteria;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatuses;
use Neos\ContentRepository\Core\Subscription\SubscriptionId;
use Neos\Error\Messages\Error;
use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Model\EventStore\Status as EventStoreStatus;
use Neos\EventStore\Model\EventStream\VirtualStreamName;

/**
 * API to set up and manage a content repository
 *
 * @api
 */
final readonly class ContentRepositoryMaintainer implements ContentRepositoryServiceInterface
{
    /**
     * @internal please use the {@see ContentRepositoryMaintainerFactory} instead!
     */
    public function __construct(
        private EventStoreInterface $eventStore,
        private SubscriptionEngine $subscriptionEngine,
        private ContentStreamPruner $contentStreamPruner
    ) {
    }

    public function setUp(): Error|null
    {
        $this->eventStore->setup();
        $eventStoreIsEmpty = iterator_count($this->eventStore->load(VirtualStreamName::all())->limit(1)) === 0;
        $setupResult = $this->subscriptionEngine->setup();
        if ($setupResult->errors !== null) {
            return self::createErrorForReason('setup', $setupResult->errors);
        }
        if ($eventStoreIsEmpty) {
            // todo reintroduce skipBooting flag, and also notify if the flag is not set, e.g. because there are events
            $bootResult = $this->subscriptionEngine->boot();
            if ($bootResult->errors !== null) {
                return self::createErrorForReason('initial catchup', $bootResult->errors);
            }
        }
        return null;
    }

    public function eventStoreStatus(): EventStoreStatus
    {
        return $this->eventStore->status();
    }

    public function subscriptionStatuses(): SubscriptionStatuses
    {
        return $this->subscriptionEngine->subscriptionStatuses();
    }

    public function replayProjection(SubscriptionId $subscriptionId, \Closure|null $progressCallback = null): Error|null
    {
        $resetResult = $this->subscriptionEngine->reset(SubscriptionEngineCriteria::create([$subscriptionId]));
        if ($resetResult->errors !== null) {
            return self::createErrorForReason('reset', $resetResult->errors);
        }
        $bootResult = $this->subscriptionEngine->boot(SubscriptionEngineCriteria::create([$subscriptionId]), $progressCallback);
        if ($bootResult->errors !== null) {
            return self::createErrorForReason('catchup', $bootResult->errors);
        }
        return null;
    }

    public function replayAllProjections(\Closure|null $progressCallback = null): Error|null
    {
        $resetResult = $this->subscriptionEngine->reset();
        if ($resetResult->errors !== null) {
            return self::createErrorForReason('reset', $resetResult->errors);
        }
        $bootResult = $this->subscriptionEngine->boot(progressCallback: $progressCallback);
        if ($bootResult->errors !== null) {
            return self::createErrorForReason('catchup', $bootResult->errors);
        }
        return null;
    }

    /**
     * Catchup one specific projection.
     *
     * The explicit catchup is required for new projections in the booting state.
     *
     * We don't offer an API to catch up all projections catchupAllProjection as we would have to distinct between booting or catchup if its active already.
     *
     * This method is only needed in rare cases for debugging or after installing a new projection or fixing its errors.
     */
    public function catchupProjection(SubscriptionId $subscriptionId, \Closure|null $progressCallback = null): Error|null
    {
        $bootResult = $this->subscriptionEngine->boot(SubscriptionEngineCriteria::create([$subscriptionId]), progressCallback: $progressCallback);
        if ($bootResult->errors !== null) {
            return self::createErrorForReason('catchup', $bootResult->errors);
        }
        if ($bootResult->numberOfProcessedEvents > 0) {
            // the projection was bootet
            return null;
        }
        // todo the projection was active, and we might still want to catch it up ... find reason for this? And combine boot and catchup?
        $catchupResult = $this->subscriptionEngine->catchUpActive(SubscriptionEngineCriteria::create([$subscriptionId]), progressCallback: $progressCallback);
        if ($catchupResult->errors !== null) {
            return self::createErrorForReason('catchup', $catchupResult->errors);
        }
        return null;
    }

    /**
     * WARNING: Removes all events from the content repository and resets the projections
     * This operation cannot be undone.
     */
    public function prune(): Error|null
    {
        // todo move pruneAllWorkspacesAndContentStreamsFromEventStream here.
        $this->contentStreamPruner->pruneAllWorkspacesAndContentStreamsFromEventStream();
        $resetResult = $this->subscriptionEngine->reset();
        if ($resetResult->errors !== null) {
            return self::createErrorForReason('reset', $resetResult->errors);
        }
        // todo reintroduce skipBooting flag to reset
        $bootResult = $this->subscriptionEngine->boot();
        if ($bootResult->errors !== null) {
            return self::createErrorForReason('booting', $bootResult->errors);
        }
        return null;
    }

    private static function createErrorForReason(string $method, Errors $errors): Error
    {
        // todo log throwable via flow???, but we are here in the CORE ...
        $message = [];
        $message[] = sprintf('%s produced the following error%s', $method, $errors->count() === 1 ? '' : 's');
        foreach ($errors as $error) {
            $message[] = sprintf('    Subscription "%s": %s', $error->subscriptionId->value, $error->message);
        }
        return new Error(join("\n", $message));
    }
}
