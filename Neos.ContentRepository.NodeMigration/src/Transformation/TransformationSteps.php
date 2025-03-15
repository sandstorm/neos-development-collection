<?php

declare(strict_types=1);

namespace Neos\ContentRepository\NodeMigration\Transformation;

/**
 * @implements \IteratorAggregate<TransformationStep>
 */
final readonly class TransformationSteps implements \IteratorAggregate, \Countable
{
    /** @var array<TransformationStep> */
    private array $items;

    private function __construct(
        TransformationStep ...$items
    ) {
        $this->items = $items;
    }

    public static function create(TransformationStep ...$items): self
    {
        return new self(...$items);
    }


    public static function createEmpty(): self
    {
        return new self();
    }

    public function merge(TransformationSteps $other): self
    {
        return new self(...[...$this->items, ...$other->items]);
    }

    public function withAppended(TransformationStep $transformationStep): self
    {
        return new self(...[...$this->items, $transformationStep]);
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
