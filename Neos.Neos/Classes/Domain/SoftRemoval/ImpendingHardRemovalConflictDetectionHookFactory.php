<?php

declare(strict_types=1);

namespace Neos\Neos\Domain\SoftRemoval;

use Neos\ContentRepository\Core\Projection\CatchUpHook\CatchUpHookFactoryDependencies;
use Neos\ContentRepository\Core\Projection\CatchUpHook\CatchUpHookFactoryInterface;
use Neos\ContentRepository\Core\Projection\CatchUpHook\CatchUpHookInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphReadModelInterface;

/**
 * @implements CatchUpHookFactoryInterface<ContentGraphReadModelInterface>
 */
final readonly class ImpendingHardRemovalConflictDetectionHookFactory implements CatchUpHookFactoryInterface
{
    public function __construct(
        private ImpendingHardRemovalConflictRepository $impendingHardRemovalConflictRepository
    ) {
    }

    public function build(CatchUpHookFactoryDependencies $dependencies): CatchUpHookInterface
    {
        return new ImpendingHardRemovalConflictDetectionHook(
            $dependencies->contentRepositoryId,
            $dependencies->projectionState,
            $this->impendingHardRemovalConflictRepository
        );
    }
}
