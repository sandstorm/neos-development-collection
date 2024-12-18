<?php

/*
 * This file is part of the Neos.Workspace.Ui package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\Workspace\Ui\ViewModel;

use Neos\Flow\Annotations as Flow;

/**
 * @implements \IteratorAggregate<WorkspaceListItem>
 */
#[Flow\Proxy(false)]
final readonly class WorkspaceListItems implements \IteratorAggregate, \Countable
{
    /**
     * @param array<WorkspaceListItem> $items
     */
    private function __construct(
        private array $items,
    ) {
    }

    /**
     * @param array<WorkspaceListItem> $items
     */
    public static function fromArray(array $items): self
    {
        foreach ($items as $item) {
            if (!$item instanceof WorkspaceListItem) {
                throw new \InvalidArgumentException(sprintf('Expected instance of %s, got: %s', WorkspaceListItem::class, get_debug_type($item)), 1718295710);
            }
        }
        return new self($items);
    }

    public function sortByTitle(bool $ascending = true): self
    {
        $items = $this->items;
        usort($items, static function (WorkspaceListItem $a, WorkspaceListItem $b) {
            return strcasecmp($a->title, $b->title);
        });
        return new self($ascending ? $items : array_reverse($items));
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
