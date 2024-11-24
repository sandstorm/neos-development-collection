<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Subscription;

use Neos\ContentRepository\Core\Projection\ProjectionSetupStatus;
use Neos\EventStore\Model\Event\SequenceNumber;

/**
 * @api
 */
final readonly class ProjectionSubscriptionStatus
{
    private function __construct(
        public SubscriptionId $subscriptionId,
        public SubscriptionStatus $subscriptionStatus,
        public SequenceNumber $subscriptionPosition,
        public SubscriptionError|null $subscriptionError,
        public ProjectionSetupStatus|null $setupStatus,
    ) {
    }

    /**
     * @internal
     */
    public static function create(
        SubscriptionId $subscriptionId,
        SubscriptionStatus $subscriptionStatus,
        SequenceNumber $subscriptionPosition,
        SubscriptionError|null $subscriptionError,
        ProjectionSetupStatus|null $setupStatus
    ): self {
        return new self($subscriptionId, $subscriptionStatus, $subscriptionPosition, $subscriptionError, $setupStatus);
    }
}
