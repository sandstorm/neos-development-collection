<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Subscription;

use Neos\ContentRepository\Core\Projection\ProjectionSetupStatusType;

/**
 * A collection of the states of the subscribers.
 *
 * Currently only projections are the available subscribers, but when the concept is extended,
 * other *SubscriptionStatus value objects will also be hold in this set.
 * Like "ListeningSubscriptionStatus" if a "ListeningSubscriber" is introduced.
 *
 * @api
 * @implements \IteratorAggregate<ProjectionSubscriptionStatus>
 */
final readonly class SubscriptionStatuses implements \IteratorAggregate
{
    /**
     * @var array<ProjectionSubscriptionStatus> $statuses
     */
    private array $statuses;

    private function __construct(
        ProjectionSubscriptionStatus ...$statuses,
    ) {
        $this->statuses = $statuses;
    }

    public static function createEmpty(): self
    {
        return new self();
    }

    /**
     * @param array<ProjectionSubscriptionStatus> $statuses
     */
    public static function fromArray(array $statuses): self
    {
        return new self(...$statuses);
    }

    public function first(): ?ProjectionSubscriptionStatus
    {
        foreach ($this->statuses as $status) {
            return $status;
        }
        return null;
    }

    public function getIterator(): \Traversable
    {
        yield from $this->statuses;
    }

    public function isEmpty(): bool
    {
        return $this->statuses === [];
    }

    public function isOk(): bool
    {
        foreach ($this->statuses as $status) {
            if ($status->subscriptionStatus === SubscriptionStatus::ERROR) {
                return false;
            }
            if ($status->setupStatus?->type !== ProjectionSetupStatusType::OK) {
                return false;
            }
        }
        return true;
    }
}
