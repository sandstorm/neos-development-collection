<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Subscription\Engine;

use Neos\ContentRepository\Core\Subscription\Store\SubscriptionCriteria;
use Neos\ContentRepository\Core\Subscription\Store\SubscriptionStoreInterface;
use Neos\ContentRepository\Core\Subscription\Subscription;
use Neos\ContentRepository\Core\Subscription\Subscriptions;

/** @internal */
final class SubscriptionManager
{
    /** @var \SplObjectStorage<Subscription, null> */
    private \SplObjectStorage $forAdd;

    /** @var \SplObjectStorage<Subscription, null> */
    private \SplObjectStorage $forUpdate;

    public function __construct(
        private readonly SubscriptionStoreInterface $subscriptionStore,
    ) {
        $this->forAdd = new \SplObjectStorage();
        $this->forUpdate = new \SplObjectStorage();
    }

    /**
     * @template T
     * @param \Closure(Subscriptions):T $closure
     * @return T
     */
    public function findForUpdate(SubscriptionCriteria $criteria, \Closure $closure): mixed
    {
        return $this->subscriptionStore->transactional(
            /** @return T */
            function () use ($closure, $criteria): mixed {
                try {
                    return $closure($this->subscriptionStore->findByCriteria($criteria));
                } finally {
                    $this->flush();
                }
            },
        );
    }

    public function find(SubscriptionCriteria $criteria): Subscriptions
    {
        return $this->subscriptionStore->findByCriteria($criteria);
    }

    public function add(Subscription $subscription): void
    {
        $this->forAdd->attach($subscription);
    }

    public function update(Subscription $subscription): void
    {
        $this->forUpdate->attach($subscription);
    }

    public function flush(): void
    {
        foreach ($this->forAdd as $subscription) {
            $this->subscriptionStore->add($subscription);
        }

        foreach ($this->forUpdate as $subscription) {
            if ($this->forAdd->contains($subscription)) {
                continue;
            }

            $this->subscriptionStore->update($subscription);
        }

        $this->forAdd = new \SplObjectStorage();
        $this->forUpdate = new \SplObjectStorage();
    }
}
