<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Projection\ContentGraph\InMemoryContentGraph\Projection;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePointSet;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;

/**
 * The active record for assigning child nodes to parent nodes in-memory
 *
 * @extends \SplObjectStorage<InMemoryNodeRecords,DimensionSpacePoint>
 * @internal
 */
final class InMemoryChildrenHyperrelation extends \SplObjectStorage
{
    public function getNodeRecordsByDimensionSpacePoint(DimensionSpacePoint $dimensionSpacePoint, ?NodeAggregateId $parent = null): ?InMemoryNodeRecords
    {
        foreach ($this as $nodeRecords) {
            if ($this->getInfo() === $dimensionSpacePoint) {
                return $nodeRecords;
            }
        }

        return null;
    }

    public function extractForDimensionSpacePointSet(DimensionSpacePointSet $dimensionSpacePointSet): self
    {
        $extraction = new self();
        foreach ($this as $nodeRecords) {
            $dimensionSpacePoint = $this->getInfo();
            if ($dimensionSpacePointSet->contains($dimensionSpacePoint)) {
                $extraction->attach($nodeRecords, $dimensionSpacePoint);
                $this->detach($nodeRecords);
            }
        }

        return $extraction;
    }
}
