<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Service;

use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryDependencies;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryInterface;

/**
 * @implements ContentRepositoryServiceFactoryInterface<SubscriptionService>
 * @api
 */
class SubscriptionServiceFactory implements ContentRepositoryServiceFactoryInterface
{
    public function build(
        ContentRepositoryServiceFactoryDependencies $serviceFactoryDependencies
    ): SubscriptionService {
        return new SubscriptionService(
            $serviceFactoryDependencies->eventStore,
            $serviceFactoryDependencies->subscriptionEngine,
        );
    }
}
