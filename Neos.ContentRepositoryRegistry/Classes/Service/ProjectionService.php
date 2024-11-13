<?php

declare(strict_types=1);

namespace Neos\ContentRepositoryRegistry\Service;

use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceInterface;
use Neos\ContentRepository\Core\Projection\CatchUpOptions;
use Neos\ContentRepository\Core\Subscription\Engine\SubscriptionEngine;

/**
 * Content Repository service to perform Projection replays
 *
 * @internal
 */
final class ProjectionReplayService implements ContentRepositoryServiceInterface
{
    public function __construct(
        private readonly SubscriptionEngine $subscriptionEngine,
    ) {
    }

    public function replayProjection(string $projectionAliasOrClassName, CatchUpOptions $options): void
    {
        $this->subscriptionEngine->setup();
        // TODO $this->subscriptionEngine->reset()
        // TODO $this->subscriptionEngine->run()
    }

    public function replayAllProjections(CatchUpOptions $options, ?\Closure $progressCallback = null): void
    {
        // TODO $this->subscriptionEngine->reset()
        // TODO $this->subscriptionEngine->run()
    }

    public function resetAllProjections(): void
    {
        // TODO $this->subscriptionEngine->reset()
    }
}
