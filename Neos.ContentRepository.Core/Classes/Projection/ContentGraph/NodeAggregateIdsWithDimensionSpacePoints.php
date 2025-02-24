<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Projection\ContentGraph;

/**
 * @implements \IteratorAggregate<NodeAggregateIdWithDimensionSpacePoints>
 * @api a simple collection
 */
final readonly class NodeAggregateIdsWithDimensionSpacePoints implements \IteratorAggregate, \Countable
{
    /** @var array<NodeAggregateIdWithDimensionSpacePoints> */
    private array $items;

    private function __construct(
        NodeAggregateIdWithDimensionSpacePoints ...$items
    ) {
        $this->items = $items;
    }

    public static function create(NodeAggregateIdWithDimensionSpacePoints ...$items): self
    {
        return new self(...$items);
    }

    /** @param array<NodeAggregateIdWithDimensionSpacePoints> $array */
    public static function fromArray(array $array): self
    {
        return new self(...$array);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }
}
