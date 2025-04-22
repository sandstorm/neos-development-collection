<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Projection\ContentGraph\InMemoryContentGraph\Projection;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
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

    public function getNodeRecordByDimensionSpacePoint(DimensionSpacePoint $dimensionSpacePoint): InMemoryNodeRecord|NullNodeRecord|null
    {
        foreach ($this as $nodeRecord) {
            if ($this->getInfo()->contains($dimensionSpacePoint)) {
                return $nodeRecord;
            }
        }

        return null;
    }

    public function extractForDimensionSpacePointSet(DimensionSpacePointSet $dimensionSpacePointSet): self
    {
        $extraction = new self();
        foreach ($this as $nodeRecord) {
            $currentDimensionSpacePointSet = $this->getInfo();
            $setToExtract = $dimensionSpacePointSet->getIntersection($currentDimensionSpacePointSet);
            if ($setToExtract->isEmpty()) {
                continue;
            }

            $setToKeep = $currentDimensionSpacePointSet->getDifference($setToExtract);
            $this->detach($nodeRecord);
            if (!$setToKeep->isEmpty()) {
                $this->attach($nodeRecord, $setToKeep);
            }

            $extraction->attach($nodeRecord, $setToExtract);
        }

        return $extraction;
    }

    public function merge(self $other): void
    {
        foreach ($other as $nodeRecords) {
            $dimensionSpacePointSet = $other->getInfo();
            $this->attach($nodeRecords, $dimensionSpacePointSet);
        }
    }
}
