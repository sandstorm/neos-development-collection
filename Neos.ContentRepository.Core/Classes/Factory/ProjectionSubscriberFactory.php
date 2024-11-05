<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Factory;

use Neos\ContentRepository\Core\Projection\ProjectionEventHandler;
use Neos\ContentRepository\Core\Projection\ProjectionFactoryInterface;
use Neos\ContentRepository\Core\Subscription\RunMode;
use Neos\ContentRepository\Core\Subscription\Subscriber\Subscriber;
use Neos\ContentRepository\Core\Subscription\SubscriptionGroup;
use Neos\ContentRepository\Core\Subscription\SubscriptionId;

final readonly class ProjectionSubscriberFactory implements ContentRepositorySubscriberFactoryInterface
{
    public function __construct(
        private SubscriptionId $subscriptionId,
        private ProjectionFactoryInterface $projectionFactory,
        private array $projectionFactoryOptions,
    ) {
    }

    public function build(SubscriberFactoryDependencies $dependencies): Subscriber
    {
        return new Subscriber(
            $this->subscriptionId,
            SubscriptionGroup::fromString('projections'),
            RunMode::FROM_BEGINNING,
            ProjectionEventHandler::create($this->projectionFactory->build($dependencies, $this->projectionFactoryOptions)),
        );
    }
}
