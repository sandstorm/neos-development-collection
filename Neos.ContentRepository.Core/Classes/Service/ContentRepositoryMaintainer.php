<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Service;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceInterface;
use Neos\ContentRepository\Core\Feature\ContentStreamEventStreamName;
use Neos\ContentRepository\Core\Feature\WorkspaceEventStreamName;
use Neos\ContentRepository\Core\Subscription\Engine\Errors;
use Neos\ContentRepository\Core\Subscription\Engine\SubscriptionEngine;
use Neos\ContentRepository\Core\Subscription\Engine\SubscriptionEngineCriteria;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatusCollection;
use Neos\ContentRepository\Core\Subscription\SubscriptionId;
use Neos\Error\Messages\Error;
use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Model\Event\EventType;
use Neos\EventStore\Model\Event\EventTypes;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\EventStore\Status as EventStoreStatus;
use Neos\EventStore\Model\EventStream\EventStreamFilter;
use Neos\EventStore\Model\EventStream\VirtualStreamName;

/**
 * Set up and manage a content repository
 *
 * Initialisation / Tear down
 * --------------------------
 * The method {@see setUp} sets up the content repository like event store and projection database tables.
 * It is non-destructive.
 *
 * Resetting a content repository with {@see prune} method will purge the event stream and reset all projection states.
 *
 * Staus information
 * -----------------
 * The status of the content repository e.g. if a setup is required or if all subscriptions are active and their position
 * can be examined with two methods:
 *
 * The event store status is available via {@see eventStoreStatus}, while the subscription status are returned
 * via {@see SubscriptionStatusCollection}. Further documentation in {@see SubscriptionStatusCollection}.
 *
 * Replay / Catchup of projections
 * -------------------------------
 * The methods {@see replayProjection}, {@see replayAllProjections} and {@see catchupProjection}
 * can be leveraged to interact with the projection catchup. In the happy path no interaction is necessary,
 * as {@see ContentRepository::handle()} triggers the projections after applying the events.
 *
 * For initialising on a new database - which contains events already - a replay will make sure that the projections
 * are emptied and reapply the events.
 *
 * The explicit catchup of a projection is only required when adding new projections after installation, of after fixing a projection error.
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
        private SubscriptionEngine $subscriptionEngine
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

    public function subscriptionStatus(): SubscriptionStatusCollection
    {
        return $this->subscriptionEngine->subscriptionStatus();
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
        // todo if a projection is in error state and will be explicit caught up here we might as well attempt that without saying the user should setup?
        // also setup then can avoid doing the repairing!
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
        // prune all streams:
        foreach ($this->findAllContentStreamStreamNames() as $contentStreamStreamName) {
            $this->eventStore->deleteStream($contentStreamStreamName);
        }
        foreach ($this->findAllWorkspaceStreamNames() as $workspaceStreamName) {
            $this->eventStore->deleteStream($workspaceStreamName);
        }
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

    /**
     * @return list<StreamName>
     */
    private function findAllContentStreamStreamNames(): array
    {
        $events = $this->eventStore->load(
            VirtualStreamName::forCategory(ContentStreamEventStreamName::EVENT_STREAM_NAME_PREFIX),
            EventStreamFilter::create(
                EventTypes::create(
                // we are only interested in the creation events to limit the amount of events to fetch
                    EventType::fromString('ContentStreamWasCreated'),
                    EventType::fromString('ContentStreamWasForked')
                )
            )
        );
        $allStreamNames = [];
        foreach ($events as $eventEnvelope) {
            $allStreamNames[] = $eventEnvelope->streamName;
        }
        return array_unique($allStreamNames, SORT_REGULAR);
    }

    /**
     * @return list<StreamName>
     */
    private function findAllWorkspaceStreamNames(): array
    {
        $events = $this->eventStore->load(
            VirtualStreamName::forCategory(WorkspaceEventStreamName::EVENT_STREAM_NAME_PREFIX),
            EventStreamFilter::create(
                EventTypes::create(
                // we are only interested in the creation events to limit the amount of events to fetch
                    EventType::fromString('RootWorkspaceWasCreated'),
                    EventType::fromString('WorkspaceWasCreated')
                )
            )
        );
        $allStreamNames = [];
        foreach ($events as $eventEnvelope) {
            $allStreamNames[] = $eventEnvelope->streamName;
        }
        return array_unique($allStreamNames, SORT_REGULAR);
    }
}
