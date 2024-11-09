<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\CommandHandler;

/**
 * Collection of {@see CommandHookInterface} instances
 *
 * @implements \IteratorAggregate<CommandHookInterface>
 * @internal
 */
final readonly class CommandHooks implements CommandHookInterface, \IteratorAggregate, \Countable
{
    /**
     * @var array<CommandHookInterface>
     */
    private array $commandHooks;

    private function __construct(
        CommandHookInterface ...$commandHooks
    ) {
        $this->commandHooks = $commandHooks;
    }

    /**
     * @param array<CommandHookInterface> $commandHooks
     */
    public static function fromArray(array $commandHooks): self
    {
        return new self(...$commandHooks);
    }

    public static function none(): self
    {
        return new self();
    }

    public function getIterator(): \Traversable
    {
        yield from $this->commandHooks;
    }

    public function count(): int
    {
        return count($this->commandHooks);
    }

    public function onBeforeHandle(CommandInterface $command): CommandInterface
    {
        foreach ($this->commandHooks as $commandHook) {
            $command = $commandHook->onBeforeHandle($command);
        }
        return $command;
    }
}
