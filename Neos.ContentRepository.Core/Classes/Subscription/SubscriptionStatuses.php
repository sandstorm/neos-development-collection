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
 * In case the subscriber is not available currently - e.g. will be detached, a {@see DetachedSubscriptionStatus} will be returned.
 * Note that ProjectionSubscriptionStatus with status == Detached can be returned, if the projection is installed again!
 *
 * @api
 * @implements \IteratorAggregate<ProjectionSubscriptionStatus|DetachedSubscriptionStatus>
 */
final readonly class SubscriptionStatuses implements \IteratorAggregate
{
    /**
     * @var array<ProjectionSubscriptionStatus|DetachedSubscriptionStatus> $statuses
     */
    private array $statuses;

    private function __construct(
        ProjectionSubscriptionStatus|DetachedSubscriptionStatus ...$statuses,
    ) {
        $this->statuses = $statuses;
    }

    public static function createEmpty(): self
    {
        return new self();
    }

    /**
     * @param array<ProjectionSubscriptionStatus|DetachedSubscriptionStatus> $statuses
     */
    public static function fromArray(array $statuses): self
    {
        return new self(...$statuses);
    }

    public function first(): ProjectionSubscriptionStatus|DetachedSubscriptionStatus|null
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
            // ignores DetachedSubscriptionStatus
            if ($status instanceof ProjectionSubscriptionStatus) {
                if ($status->subscriptionStatus === SubscriptionStatus::ERROR) {
                    return false;
                }
                if ($status->setupStatus->type !== ProjectionSetupStatusType::OK) {
                    return false;
                }
            }
        }
        return true;
    }
}
