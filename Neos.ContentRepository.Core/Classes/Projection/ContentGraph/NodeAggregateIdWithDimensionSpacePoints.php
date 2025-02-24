<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Projection\ContentGraph;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePointSet;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;

/** @api a simple set */
final readonly class NodeAggregateIdWithDimensionSpacePoints
{
    private function __construct(
        public NodeAggregateId $nodeAggregateId,
        public DimensionSpacePointSet $dimensionSpacePointSet
    ) {
    }

    public static function create(
        NodeAggregateId $nodeAggregateId,
        DimensionSpacePointSet $dimensionSpacePointSet
    ): self {
        return new self($nodeAggregateId, $dimensionSpacePointSet);
    }
}
