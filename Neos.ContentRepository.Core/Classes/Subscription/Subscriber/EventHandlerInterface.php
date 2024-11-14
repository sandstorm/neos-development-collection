<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Subscription\Subscriber;

use Neos\ContentRepository\Core\EventStore\EventInterface;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\EventStore\Model\EventEnvelope;

/**
 * @internal
 */
interface EventHandlerInterface
{
    /**
     * Set up this handler (e.g. creating required database tables, ...)
     * Note: Some handlers might not need an explicit setup – for those this method can just be a no-op
     */
    public function setup(): void;
    public function onBeforeCatchUp(SubscriptionStatus $subscriptionStatus): void;
    public function handle(EventInterface $event, EventEnvelope $eventEnvelope): void;
    public function onAfterCatchUp(): void;
}
