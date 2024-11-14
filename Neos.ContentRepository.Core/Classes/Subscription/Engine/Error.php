<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Subscription\Engine;

use Neos\ContentRepository\Core\Subscription\SubscriptionId;

/**
 * @internal
 */
final class Error
{
    private function __construct(
        public readonly SubscriptionId $subscriptionId,
        public readonly string $message,
        public readonly \Throwable $throwable,
    ) {
    }

    public static function fromSubscriptionIdAndException(SubscriptionId $subscriptionId, \Throwable $exception): self
    {
        return new self(
            $subscriptionId,
            $exception->getMessage(),
            $exception,
        );
    }
}
