<?php

/*
 * This file is part of the Neos.ContentRepository.Core package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Feature\WorkspaceRebase;

/**
 * @implements \IteratorAggregate<int,EventThatFailedDuringRebase>
 *
 * @api part of the exception exposed when rebasing failed
 */
final readonly class EventsThatFailedDuringRebase implements \IteratorAggregate, \Countable
{
    /**
     * @var array<int,EventThatFailedDuringRebase>
     */
    private array $items;

    public function __construct(EventThatFailedDuringRebase ...$items)
    {
        $this->items = array_values($items);
    }

    public function withAppended(EventThatFailedDuringRebase $item): self
    {
        return new self(...[...$this->items, $item]);
    }

    public function first(): ?EventThatFailedDuringRebase
    {
        return $this->items[0] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
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
