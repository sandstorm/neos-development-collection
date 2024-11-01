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
        private CommandHooks $commandHooks,
        CommandHandlerInterface ...$handlers
    ) {
        $this->handlers = $handlers;
    }

    /**
     * @return EventsToPublish|\Generator<int, EventsToPublish>
     */
    public function handle(CommandInterface $command): EventsToPublish|\Generator
    {
        // multiple handlers must not handle the same command
        foreach ($this->handlers as $handler) {
            if (!$handler->canHandle($command)) {
                continue;
            }
            foreach ($this->commandHooks as $commandHook) {
                $command = $commandHook->beforeHandle($command);
            }
            return $handler->handle($command, $this->commandHandlingDependencies);
        }
        throw new \RuntimeException(sprintf('No handler found for Command "%s"', get_debug_type($command)), 1649582778);
    }

    public function withAdditionalHandlers(CommandHandlerInterface ...$handlers): self
    {
        return new self(
            $this->commandHandlingDependencies,
            $this->commandHooks,
            ...$this->handlers,
            ...$handlers,
        );
    }

    public function withCommandHooks(CommandHooks $commandHooks): self
    {
        return new self(
            $this->commandHandlingDependencies,
            $commandHooks,
            ...$this->handlers,
        );
    }
}
