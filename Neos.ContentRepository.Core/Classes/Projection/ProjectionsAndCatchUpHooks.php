<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Projection;

use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphProjectionInterface;

/**
 * @internal
 */
final readonly class ProjectionsAndCatchUpHooks
{
    public Projections $projections;

    /**
     * @param array<class-string<ProjectionInterface<ProjectionStateInterface>>, CatchUpHookFactories> $catchUpHookFactoriesByProjectionClassName
     */
    public function __construct(
        public ContentGraphProjectionInterface $contentGraphProjection,
        Projections $additionalProjections,
        private array $catchUpHookFactoriesByProjectionClassName,
    ) {
        $this->projections = $additionalProjections->with($this->contentGraphProjection);
    }

    /**
     * @param ProjectionInterface<ProjectionStateInterface> $projection
     * @return ?CatchUpHookFactoryInterface<ProjectionStateInterface>
     */
    public function getCatchUpHookFactoryForProjection(ProjectionInterface $projection): ?CatchUpHookFactoryInterface
    {
        return $this->catchUpHookFactoriesByProjectionClassName[$projection::class] ?? null;
    }
}
