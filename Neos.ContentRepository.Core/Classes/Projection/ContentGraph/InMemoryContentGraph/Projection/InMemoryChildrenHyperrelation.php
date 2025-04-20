<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Projection\ContentGraph\InMemoryContentGraph\Projection;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;

/**
 * The active record for assigning child nodes to parent nodes in-memory
 *
 * @extends \SplObjectStorage<InMemoryNodeRecords,DimensionSpacePoint>
 * @internal
 */
final class InMemoryChildrenHyperrelation extends \SplObjectStorage
{
    public function getNodeRecordByDimensionSpacePoint(DimensionSpacePoint $dimensionSpacePoint): ?InMemoryNodeRecords
    {
        foreach ($this as $nodeRecords) {
            if ($this->getInfo() === $dimensionSpacePoint) {
                return $nodeRecords;
            }
        }

        return null;
    }
}
