<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Subscription\Engine;

use Neos\ContentRepository\Core\Subscription\SubscriptionId;

/**
 * @internal implementation detail of the catchup
 */
final readonly class Error
{
    private function __construct(
        public SubscriptionId $subscriptionId,
        public string $message,
        public \Throwable $throwable,
    ) {
    }

    public static function forSubscription(SubscriptionId $subscriptionId, \Throwable $exception): self
    {
        return new self(
            $subscriptionId,
            $exception->getMessage(),
            $exception,
        );
    }
}
