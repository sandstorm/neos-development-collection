<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\CommandHandler;

use Neos\ContentRepository\Core\EventStore\EventsToPublish;
use Neos\EventStore\Model\EventStore\CommitResult;

/**
 * Common interface for all Content Repository command handlers
 *
 * The {@see CommandHandlingDependencies} are available during handling to do soft-constraint checks
 *
 * @phpstan-type YieldedEventsToPublish \Generator<int, EventsToPublish, CommitResult|null, void>
 * @internal no public API, because commands are no extension points of the CR
 */
interface CommandHandlerInterface
{
    public function canHandle(CommandInterface $command): bool;

    /**
     * "simple" command handlers return EventsToPublish directly
     *
     * For the case of the workspace command handler that need to publish to many streams and "close" the content-stream directly,
     * it's allowed to yield the events to interact with the control flow of event publishing.
     *
     * @return EventsToPublish|YieldedEventsToPublish
     */
    public function handle(CommandInterface $command, CommandHandlingDependencies $commandHandlingDependencies): EventsToPublish|\Generator;
}
