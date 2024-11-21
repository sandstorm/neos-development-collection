<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Subscription\Subscriber;

use Neos\ContentRepository\Core\Projection\ProjectionEventHandler;
use Neos\ContentRepository\Core\Subscription\SubscriptionId;

/**
 * @api
 */
final class Subscriber
{
    public function __construct(
        public readonly SubscriptionId $id,
        public readonly ProjectionEventHandler $handler,
    ) {
    }
}
