<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Projection\ContentGraph\InMemoryContentGraph\Projection;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;

/**
 * The active record for connecting parent nodes to child nodes in-memory
 *
 * @internal
 */
final class InMemoryHierarchyHyperrelationRecord
{
    public function __construct(
        public ?InMemoryNodeRecord $parent,
        public DimensionSpacePoint $dimensionSpacePoint,
        public InMemoryNodeRecords $children,
    ) {
    }

    public function toJson(): string
    {
        return json_encode([
            'parent' => $this->parent?->toJson(),
            'dimensionSpacePoint' => $this->dimensionSpacePoint->toJson(),
            'children' => array_map(
                fn (InMemoryNodeRecord $record) => $record->toJson(),
                iterator_to_array($this->children)
            )
        ]);
    }
}
