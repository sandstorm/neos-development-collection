<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Subscription;

use Neos\ContentRepository\Core\Projection\ProjectionStatus;
use Neos\EventStore\Model\Event\SequenceNumber;

/**
 * @api
 */
final readonly class SubscriptionAndProjectionStatus
{
    private function __construct(
        public SubscriptionId $subscriptionId,
        public SubscriptionStatus $subscriptionStatus,
        public SequenceNumber $subscriptionPosition,
        public SubscriptionError|null $subscriptionError,
        public ProjectionStatus|null $projectionStatus,
    ) {
    }

    public static function create(
        SubscriptionId $subscriptionId,
        SubscriptionStatus $subscriptionStatus,
        SequenceNumber $subscriptionPosition,
        SubscriptionError|null $subscriptionError,
        ProjectionStatus|null $projectionStatus
    ): self {
        return new self($subscriptionId, $subscriptionStatus, $subscriptionPosition, $subscriptionError, $projectionStatus);
    }
}
