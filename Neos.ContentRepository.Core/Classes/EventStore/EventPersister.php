<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\EventStore;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Exception\ConcurrencyException;
use Neos\EventStore\Model\Events;
use Neos\EventStore\Model\EventStore\CommitResult;

/**
 * Internal service to persist {@see EventInterface} with the proper normalization, and triggering the
 * projection catchup process.
 *
 * @internal
 */
final readonly class EventPersister
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private EventNormalizer $eventNormalizer,
    ) {
    }

    /**
     * @throws ConcurrencyException in case the expectedVersion does not match
     */
    public function publishEvents(ContentRepository $contentRepository, EventsToPublish $eventsToPublish): void
    {
        if ($eventsToPublish->events->isEmpty()) {
            return;
        }
        $this->publishWithoutCatchup($eventsToPublish);
        $contentRepository->catchUpProjections();
    }

    /**
     * @throws ConcurrencyException in case the expectedVersion does not match
     */
    public function publishWithoutCatchup(EventsToPublish $eventsToPublish): CommitResult
    {
        $normalizedEvents = Events::fromArray(
            $eventsToPublish->events->map($this->eventNormalizer->normalize(...))
        );
        return $this->eventStore->commit(
            $eventsToPublish->streamName,
            $normalizedEvents,
            $eventsToPublish->expectedVersion
        );
    }
}
