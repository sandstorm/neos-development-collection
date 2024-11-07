<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\CommandHandler;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\EventStore\EventsToPublish;

/**
 * Implementation Detail of {@see ContentRepository::handle}, which does the command dispatching to the different
 * {@see CommandHandlerInterface} implementation.
 *
 * @internal
 */
final readonly class CommandBus
{
    /**
     * @var CommandHandlerInterface[]
     */
    private array $handlers;

    public function __construct(
        // todo pass $commandHandlingDependencies in each command handler instead of into the commandBus
        private CommandHandlingDependencies $commandHandlingDependencies,
        CommandHandlerInterface ...$handlers
    ) {
        $this->handlers = $handlers;
    }

    /**
     * @return EventsToPublish|\Generator<int, EventsToPublish>
     */
    public function handle(CommandInterface|SerializedCommandInterface $command): EventsToPublish|\Generator
    {
        // multiple handlers must not handle the same command
        foreach ($this->handlers as $handler) {
            if ($handler->canHandle($command)) {
                return $handler->handle($command, $this->commandHandlingDependencies);
            }
        }
        throw new \RuntimeException(sprintf('No handler found for Command "%s"', get_debug_type($command)), 1649582778);
    }

    public function withAdditionalHandlers(CommandHandlerInterface ...$handlers): self
    {
        return new self(
            $this->commandHandlingDependencies,
            ...$this->handlers,
            ...$handlers,
        );
    }
}
