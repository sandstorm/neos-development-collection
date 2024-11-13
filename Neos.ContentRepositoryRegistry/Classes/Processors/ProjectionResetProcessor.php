<?php
declare(strict_types=1);

namespace Neos\ContentRepositoryRegistry\Processors;

use Neos\ContentRepository\Export\ProcessingContext;
use Neos\ContentRepository\Export\ProcessorInterface;
use Neos\ContentRepositoryRegistry\Service\ProjectionService;

/**
 * @internal
 */
final class ProjectionResetProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ProjectionService $projectionService,
    ) {
    }

    public function run(ProcessingContext $context): void
    {
        $this->projectionService->resetAllProjections();
    }
}
