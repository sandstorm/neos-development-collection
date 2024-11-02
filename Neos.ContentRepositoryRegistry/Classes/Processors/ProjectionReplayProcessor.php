<?php
declare(strict_types=1);

namespace Neos\ContentRepositoryRegistry\Processors;

use Neos\ContentRepository\Core\Projection\CatchUpOptions;
use Neos\ContentRepository\Export\ProcessingContext;
use Neos\ContentRepository\Export\ProcessorInterface;
use Neos\ContentRepositoryRegistry\Service\ProjectionService;

/**
 * @internal
 */
final class ProjectionReplayProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ProjectionService $projectionService,
    ) {
    }

    public function run(ProcessingContext $context): void
    {
        $this->projectionService->replayAllProjections(CatchUpOptions::create());
    }
}
