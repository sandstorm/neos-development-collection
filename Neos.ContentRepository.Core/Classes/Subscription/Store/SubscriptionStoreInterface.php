<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Subscription\Store;

use Neos\ContentRepository\Core\Subscription\Subscription;
use Neos\ContentRepository\Core\Subscription\SubscriptionId;
use Neos\ContentRepository\Core\Subscription\Subscriptions;

/**
 * @api
 */
interface SubscriptionStoreInterface
{
    public function setup(): void;

    public function findOneById(SubscriptionId $subscriptionId): ?Subscription;

    public function findByCriteria(SubscriptionCriteria $criteria): Subscriptions;

    public function add(Subscription $subscription): void;

    public function update(Subscription $subscription): void;
}
