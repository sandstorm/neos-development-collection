<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\EventStore;

/**
 * A set of Content Repository "domain events"
 *
 * @implements \IteratorAggregate<EventInterface|DecoratedEvent>
 * @internal only used during event publishing (from within command handlers) - and their implementation is not API
 */
final readonly class Events implements \IteratorAggregate, \Countable
{
    /**
     * @var non-empty-array<EventInterface|DecoratedEvent>
     */
    public array $items;

    private function __construct(EventInterface|DecoratedEvent ...$events)
    {
        /** @var non-empty-array<EventInterface|DecoratedEvent> $events */
        $this->items = $events;
    }

    public static function with(EventInterface|DecoratedEvent $event): self
    {
        return new self($event);
    }

    public function withAppendedEvents(Events $events): self
    {
        return new self(...$this->items, ...$events->items);
    }

    /**
     * @param non-empty-array<EventInterface|DecoratedEvent> $events
     * @return static
     */
    public static function fromArray(array $events): self
    {
        return new self(...$events);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->items;
    }

    /**
     * @template T
     * @param \Closure(EventInterface|DecoratedEvent $event): T $callback
     * @return non-empty-list<T>
     */
    public function map(\Closure $callback): array
    {
        return array_map($callback, $this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }
}
