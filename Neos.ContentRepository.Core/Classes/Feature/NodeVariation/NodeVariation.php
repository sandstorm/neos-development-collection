<?php

/*
 * This file is part of the Neos.ContentRepository package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Feature\NodeVariation;

use Neos\ContentRepository\Core\CommandHandler\CommandHandlingDependencies;
use Neos\ContentRepository\Core\DimensionSpace\Exception\DimensionSpacePointNotFound;
use Neos\ContentRepository\Core\EventStore\EventsToPublish;
use Neos\ContentRepository\Core\Feature\Common\ConstraintChecks;
use Neos\ContentRepository\Core\Feature\RebaseableCommand;
use Neos\ContentRepository\Core\Feature\Common\NodeVariationInternals;
use Neos\ContentRepository\Core\Feature\ContentStreamEventStreamName;
use Neos\ContentRepository\Core\Feature\NodeVariation\Command\CreateNodeVariant;
use Neos\ContentRepository\Core\Feature\NodeVariation\Exception\DimensionSpacePointIsAlreadyOccupied;
use Neos\ContentRepository\Core\SharedModel\Exception\ContentStreamDoesNotExistYet;
use Neos\ContentRepository\Core\SharedModel\Exception\DimensionSpacePointIsNotYetOccupied;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeAggregateCurrentlyExists;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeAggregateDoesCurrentlyNotCoverDimensionSpacePoint;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeAggregatesTypeIsAmbiguous;

/**
 * @internal implementation detail of Command Handlers
 */
trait NodeVariation
{
    use NodeVariationInternals;
    use ConstraintChecks;

    /**
     * @throws ContentStreamDoesNotExistYet
     * @throws NodeAggregateCurrentlyExists
     * @throws DimensionSpacePointNotFound
     * @throws NodeAggregatesTypeIsAmbiguous
     * @throws DimensionSpacePointIsNotYetOccupied
     * @throws DimensionSpacePointIsAlreadyOccupied
     * @throws NodeAggregateDoesCurrentlyNotCoverDimensionSpacePoint
     */
    private function handleCreateNodeVariant(
        CreateNodeVariant $command,
        CommandHandlingDependencies $commandHandlingDependencies
    ): EventsToPublish {
        $this->requireContentStream($command->workspaceName, $commandHandlingDependencies);
        $contentGraph = $commandHandlingDependencies->getContentGraph($command->workspaceName);
        $expectedVersion = $this->getExpectedVersionOfContentStream($contentGraph->getContentStreamId(), $commandHandlingDependencies);
        $nodeAggregate = $this->requireProjectedNodeAggregate(
            $contentGraph,
            $command->nodeAggregateId
        );
        // we do this check first, because it gives a more meaningful error message on what you need to do.
        // we cannot use sentences with "." because the UI will only print the 1st sentence :/
        $this->requireNodeAggregateToNotBeRoot($nodeAggregate, 'and Root Node Aggregates cannot be varied; If this error happens, you most likely need to run a node migration "UpdateRootNodeAggregateDimensions" to update the root node dimensions.');
        $this->requireDimensionSpacePointToExist($command->sourceOrigin->toDimensionSpacePoint());
        $this->requireDimensionSpacePointToExist($command->targetOrigin->toDimensionSpacePoint());
        $this->requireNodeAggregateToBeUntethered($nodeAggregate);
        $this->requireNodeAggregateToOccupyDimensionSpacePoint($nodeAggregate, $command->sourceOrigin);
        $this->requireNodeAggregateToNotOccupyDimensionSpacePoint($nodeAggregate, $command->targetOrigin);

        if ($command->precedingSiblingNodeAggregateId) {
            $this->requireProjectedNodeAggregate($contentGraph, $command->precedingSiblingNodeAggregateId);
        }
        if ($command->succeedingSiblingNodeAggregateId) {
            $this->requireProjectedNodeAggregate($contentGraph, $command->succeedingSiblingNodeAggregateId);
        }
        if ($command->parentNodeAggregateId) {
            $parentNodeAggregate = $this->requireProjectedNodeAggregate($contentGraph, $command->parentNodeAggregateId);
            if ($command->precedingSiblingNodeAggregateId) {
                $this->requireNodeAggregateToBeChild(
                    $contentGraph,
                    $command->precedingSiblingNodeAggregateId,
                    $command->parentNodeAggregateId,
                    $command->targetOrigin->toDimensionSpacePoint(),
                );
            }
            if ($command->succeedingSiblingNodeAggregateId) {
                $this->requireNodeAggregateToBeChild(
                    $contentGraph,
                    $command->succeedingSiblingNodeAggregateId,
                    $command->parentNodeAggregateId,
                    $command->targetOrigin->toDimensionSpacePoint(),
                );
            }
            if ($nodeAggregate->nodeName) {
                $this->requireNodeNameToBeUncovered($contentGraph, $nodeAggregate->nodeName, $command->parentNodeAggregateId);
                $this->requireNodeTypeNotToDeclareTetheredChildNodeName($parentNodeAggregate->nodeTypeName, $nodeAggregate->nodeName);
            }
            $this->requireConstraintsImposedByAncestorsAreMet(
                $contentGraph,
                $this->requireNodeType($nodeAggregate->nodeTypeName),
                [$command->parentNodeAggregateId],
            );
        } else {
            $parentNodeAggregate = $this->requireProjectedParentNodeAggregate(
                $contentGraph,
                $command->nodeAggregateId,
                $command->sourceOrigin
            );
            if ($command->precedingSiblingNodeAggregateId) {
                $this->requireNodeAggregateToBeSibling(
                    $contentGraph,
                    $command->nodeAggregateId,
                    $command->precedingSiblingNodeAggregateId,
                    $command->targetOrigin->toDimensionSpacePoint(),
                );
            }
            if ($command->succeedingSiblingNodeAggregateId) {
                $this->requireNodeAggregateToBeSibling(
                    $contentGraph,
                    $command->nodeAggregateId,
                    $command->succeedingSiblingNodeAggregateId,
                    $command->targetOrigin->toDimensionSpacePoint(),
                );
            }
        }

        $this->requireNodeAggregateToCoverDimensionSpacePoint(
            $parentNodeAggregate,
            $command->targetOrigin->toDimensionSpacePoint()
        );

        $events = $this->createEventsForVariations(
            $contentGraph,
            $command->sourceOrigin,
            $command->targetOrigin,
            $command->parentNodeAggregateId,
            $command->precedingSiblingNodeAggregateId,
            $command->succeedingSiblingNodeAggregateId,
            $nodeAggregate
        );

        return new EventsToPublish(
            ContentStreamEventStreamName::fromContentStreamId($contentGraph->getContentStreamId())->getEventStreamName(),
            RebaseableCommand::enrichWithCommand(
                $command,
                $events
            ),
            $expectedVersion
        );
    }
}
