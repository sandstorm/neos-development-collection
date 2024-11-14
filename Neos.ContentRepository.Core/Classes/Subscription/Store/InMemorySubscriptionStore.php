<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Subscription\Store;

use Neos\ContentRepository\Core\Subscription\Subscription;
use Neos\ContentRepository\Core\Subscription\SubscriptionId;
use Neos\ContentRepository\Core\Subscription\Subscriptions;

/**
 * @internal
 */
final class InMemorySubscriptionStore implements SubscriptionStoreInterface
{
    private Subscriptions $subscriptions;

    public function __construct()
    {
        $this->subscriptions = Subscriptions::none();
    }

    public function setup(): void
    {
        // no setup required
    }

    public function findByCriteria(SubscriptionCriteria $criteria): Subscriptions
    {
        return $this->subscriptions->filter(function (Subscription $subscription) use ($criteria) {
            if ($criteria->ids !== null && !$criteria->ids->contain($subscription->id)) {
                return false;
            }
            if ($criteria->groups !== null && !$criteria->groups->contain($subscription->group)) {
                return false;
            }
            if (!$criteria->status->matches($subscription->status)) {
                return false;
            }
            return true;
        });
    }

    public function add(Subscription $subscription): void
    {
        $this->subscriptions = $this->subscriptions->with($subscription);
    }

    public function update(Subscription $subscription): void
    {
        $this->subscriptions = $this->subscriptions->with($subscription);
    }

    public function remove(Subscription $subscription): void
    {
        $this->subscriptions = $this->subscriptions->without($subscription->id);
    }

    public function transactional(\Closure $closure): mixed
    {
        // In memory store does not support transaction boundaries
        return $closure();
    }
}
