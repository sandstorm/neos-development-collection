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

namespace Neos\ContentRepository\Core\Projection\CatchUpHook;

use Neos\ContentRepository\Core\Dimension\ContentDimensionSourceInterface;
use Neos\ContentRepository\Core\DimensionSpace\InterDimensionalVariationGraph;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ProjectionStateInterface as T;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;

/**
 * @template-covariant T of T
 *
 * @api provides available dependencies for implementing a catch-up hook.
 */
final readonly class CatchUpHookFactoryDependencies
{
    /**
     * @param ContentRepositoryId $contentRepositoryId the content repository the catchup was registered in
     * @param T&T $projectionState the state of the projection the catchup was registered to (Its only safe to access this projections state)
     */
    public function __construct(
        public ContentRepositoryId $contentRepositoryId,
        public T $projectionState,
        public NodeTypeManager $nodeTypeManager,
        public ContentDimensionSourceInterface $contentDimensionSource,
        public InterDimensionalVariationGraph $variationGraph
    ) {
    }
}
