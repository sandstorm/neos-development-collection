<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Projection\ContentGraph\InMemoryContentGraph\Projection;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePointSet;

/**
 * The active record for assigning parent nodes to child nodes in-memory
 *
 * @extends \SplObjectStorage<InMemoryNodeRecord|NullNodeRecord,DimensionSpacePointSet>
 * @internal
 */
final class InMemoryParentHyperrelation extends \SplObjectStorage
{
    public function getCoveredDimensionSpacePointSet(): DimensionSpacePointSet
    {
        $dimensionSpacePoints = DimensionSpacePointSet::fromArray([]);
        foreach ($this as $nodeRecord) {
            $dimensionSpacePoints = $dimensionSpacePoints->getUnion($this->getInfo());
        }
        return $dimensionSpacePoints;
    }
}
