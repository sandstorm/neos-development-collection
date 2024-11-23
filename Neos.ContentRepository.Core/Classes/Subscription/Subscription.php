<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Subscription;

use Neos\ContentRepository\Core\Subscription\Engine\SubscriptionEngine;
use Neos\ContentRepository\Core\Subscription\Subscriber\ProjectionSubscriber;
use Neos\EventStore\Model\Event\SequenceNumber;

/**
 * Note: This class is mutable by design!
 *
 * @internal
 */
final class Subscription
{
    public function __construct(
        public readonly SubscriptionId $id,
        public SubscriptionStatus $status,
        public SequenceNumber $position,
        public SubscriptionError|null $error = null,
        public readonly \DateTimeImmutable|null $lastSavedAt = null,
    ) {
    }

    /**
     * @internal Only the {@see SubscriptionEngine} is supposed to instantiate subscriptions
     */
    public static function createFromSubscriber(ProjectionSubscriber $subscriber): self
    {
        return new self(
            $subscriber->id,
            SubscriptionStatus::NEW,
            SequenceNumber::fromInteger(0),
        );
    }

    /**
     * @internal Only the {@see SubscriptionEngine} is supposed to mutate subscriptions
     */
    public function set(
        SubscriptionStatus $status = null,
        SequenceNumber $position = null
    ): void {
        $this->status = $status ?? $this->status;
        $this->position = $position ?? $this->position;
    }

    /**
     * @internal Only the {@see SubscriptionEngine} is supposed to mutate subscriptions
     */
    public function fail(\Throwable $exception): void
    {
        $this->error = SubscriptionError::fromPreviousStatusAndException($this->status, $exception);
        $this->status = SubscriptionStatus::ERROR;
    }
}
