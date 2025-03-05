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

namespace Neos\ContentRepository\Core\Feature\NodeDisabling\Command;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\UntagSubtree;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Dto\SubtreeTag;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Event\SubtreeWasUntagged;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeVariantSelectionStrategy;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;

/**
 * Enable the given node aggregate in the given content stream in a dimension space point using a given strategy
 *
 * With Neos 9 Beta 8 the generic concept of subtree tags was introduced. Enabling publishes since then {@see SubtreeWasUntagged}.
 * The duplicated command implementation was removed with Neos 9 Beta 19 and its now discouraged to use these legacy commands
 * which now translate fully to their subtree counterparts.
 *
 * @deprecated please use {@see UntagSubtree} instead and specify as {@see SubtreeTag} "disabled"
 * @internal
 */
final readonly class EnableNodeAggregate
{
    /**
     * @param WorkspaceName $workspaceName The content stream in which the enable operation is to be performed
     * @param NodeAggregateId $nodeAggregateId The identifier of the node aggregate to enable
     * @param DimensionSpacePoint $coveredDimensionSpacePoint The covered dimension space point of the node aggregate in which the user intends to enable it
     * @param NodeVariantSelectionStrategy $nodeVariantSelectionStrategy The strategy the user chose to determine which specialization variants will also be enabled
     */
    public static function create(WorkspaceName $workspaceName, NodeAggregateId $nodeAggregateId, DimensionSpacePoint $coveredDimensionSpacePoint, NodeVariantSelectionStrategy $nodeVariantSelectionStrategy): UntagSubtree
    {
        return UntagSubtree::create(
            $workspaceName,
            $nodeAggregateId,
            $coveredDimensionSpacePoint,
            $nodeVariantSelectionStrategy,
            SubtreeTag::disabled()
        );
    }

    /** @param array<string,mixed> $array */
    public static function fromArray(array $array): UntagSubtree
    {
        return UntagSubtree::create(
            WorkspaceName::fromString($array['workspaceName']),
            NodeAggregateId::fromString($array['nodeAggregateId']),
            DimensionSpacePoint::fromArray($array['coveredDimensionSpacePoint']),
            NodeVariantSelectionStrategy::from($array['nodeVariantSelectionStrategy']),
            SubtreeTag::disabled()
        );
    }
}
