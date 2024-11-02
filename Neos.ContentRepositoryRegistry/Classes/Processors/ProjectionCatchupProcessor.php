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
final class ProjectionCatchupProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ProjectionService $projectionservice,
    ) {
    }

    public function run(ProcessingContext $context): void
    {
        $this->projectionservice->catchupAllProjections(CatchUpOptions::create());
    }
}
