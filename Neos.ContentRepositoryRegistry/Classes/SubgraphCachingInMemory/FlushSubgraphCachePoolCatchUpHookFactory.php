<?php

declare(strict_types=1);

namespace Neos\ContentRepositoryRegistry\SubgraphCachingInMemory;

use Neos\ContentRepository\Core\Projection\CatchUpHookFactoryInterface;
use Neos\ContentRepository\Core\Projection\CatchUpHookInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphReadModelInterface;
use Neos\ContentRepository\Core\Projection\ProjectionStateInterface;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;

/**
 * Factory for {@see FlushSubgraphCachePoolCatchUpHook}, auto-registered in Settings.yaml for GraphProjection
 *
 * @implements CatchUpHookFactoryInterface<ContentGraphReadModelInterface>
 * @internal
 */
class FlushSubgraphCachePoolCatchUpHookFactory implements CatchUpHookFactoryInterface
{

    public function __construct(
        private readonly SubgraphCachePool $subgraphCachePool
    ) {
    }

    public function build(ContentRepositoryId $contentRepositoryId, ProjectionStateInterface $projectionState): CatchUpHookInterface
    {
        return new FlushSubgraphCachePoolCatchUpHook($this->subgraphCachePool);
    }
}
