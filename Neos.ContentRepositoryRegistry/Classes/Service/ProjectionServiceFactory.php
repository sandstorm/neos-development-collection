<?php
declare(strict_types=1);

namespace Neos\ContentRepositoryRegistry\Service;

use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryDependencies;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryInterface;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceInterface;
use Neos\Flow\Annotations as Flow;

/**
 * Factory for the {@see ProjectionService}
 *
 * @implements ContentRepositoryServiceFactoryInterface<ProjectionService>
 * @internal
 */
#[Flow\Scope("singleton")]
final class ProjectionServiceFactory implements ContentRepositoryServiceFactoryInterface
{
    public function build(ContentRepositoryServiceFactoryDependencies $serviceFactoryDependencies): ContentRepositoryServiceInterface
    {
        return new ProjectionService(
            $serviceFactoryDependencies->projectionsAndCatchUpHooks->projections,
            $serviceFactoryDependencies->contentRepository,
            $serviceFactoryDependencies->eventStore,
        );
    }
}
