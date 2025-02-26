<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Projection\ContentGraph;

use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateIds;

/**
 * @implements \IteratorAggregate<NodeAggregateIdWithDimensionSpacePoints>
 * @api a simple collection
 */
final readonly class NodeAggregateIdsWithDimensionSpacePoints implements \IteratorAggregate, \Countable
{
    private function __construct(
        /** @var array<string,NodeAggregateIdWithDimensionSpacePoints> indexed by NodeAggregateId */
        private array $items
    ) {
    }

    public static function create(NodeAggregateIdWithDimensionSpacePoints ...$items): self
    {
        $indexedItems = [];
        foreach ($items as $item) {
            $indexedItems[$item->nodeAggregateId->value] = $item;
        }
        return new self($indexedItems);
    }

    /** @param array<NodeAggregateIdWithDimensionSpacePoints> $items */
    public static function fromArray(array $items): self
    {
        return self::create(...$items);
    }

    public function get(NodeAggregateId $key): ?NodeAggregateIdWithDimensionSpacePoints
    {
        return $this->items[$key->value] ?? null;
    }

    public function with(NodeAggregateIdWithDimensionSpacePoints $item): self
    {
        $items = $this->items;
        $items[$item->nodeAggregateId->value] = $item;
        return new self($items);
    }

    public function merge(self $other): self
    {
        return new self(array_merge($this->items, $other->items));
    }

    public function toNodeAggregateIds(): NodeAggregateIds
    {
        return NodeAggregateIds::fromArray(
            array_map(fn (NodeAggregateIdWithDimensionSpacePoints $node) => $node->nodeAggregateId, $this->items)
        );
    }

    public function getIterator(): \Traversable
    {
        yield from array_values($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return count($this->items);
    }
}
