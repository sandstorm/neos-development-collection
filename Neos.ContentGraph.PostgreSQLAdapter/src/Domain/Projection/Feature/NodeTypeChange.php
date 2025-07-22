<?php

/*
 * This file is part of the Neos.ContentGraph.PostgreSQLAdapter package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\ContentGraph\PostgreSQLAdapter\Domain\Projection\Feature;

use Doctrine\DBAL\Connection;
use Neos\ContentGraph\PostgreSQLAdapter\Domain\Projection\NodeRecord;
use Neos\ContentGraph\PostgreSQLAdapter\Domain\Projection\ProjectionReadQueries;
use Neos\ContentRepository\Core\Feature\NodeTypeChange\Event\NodeAggregateTypeWasChanged;

/**
 * The node disabling feature set for the hypergraph projector
 *
 * @internal
 */
trait NodeTypeChange
{
    use CopyOnWrite;

    /**
     * @throws \Throwable
     */
    private function whenNodeAggregateTypeWasChanged(NodeAggregateTypeWasChanged $event): void
    {
        foreach (
            $this->getReadQueries()->findNodeRecordsForNodeAggregate(
                $event->contentStreamId,
                $event->nodeAggregateId
            ) as $originNode
        ) {
            $this->copyOnWrite(
                $event->contentStreamId,
                $originNode,
                function (NodeRecord $nodeRecord) use ($event) {
                    $nodeRecord->nodeTypeName = $event->newNodeTypeName;
                }
            );
        }
    }

    abstract protected function getReadQueries(): ProjectionReadQueries;

    abstract protected function getDatabaseConnection(): Connection;
}
