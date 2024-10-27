<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Projection;

use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;

/**
 * @template T of ProjectionStateInterface
 * @api
 */
interface CatchUpHookFactoryInterface
{
    /**
     * Note that a catchup doesn't have access to the full content repository, as it would allow full recursion via handle and accessing other projections
     * state is not safe as the other projection might not be behind - the order is undefined.
     *
     * @param ContentRepositoryId $contentRepositoryId the content repository the catchup was registered in
     * @param ProjectionStateInterface&T $projectionState the state of the projection the catchup was registered to (Its only safe to access this projections state)
     * @return CatchUpHookInterface
     */
    public function build(ContentRepositoryId $contentRepositoryId, ProjectionStateInterface $projectionState): CatchUpHookInterface;
}
