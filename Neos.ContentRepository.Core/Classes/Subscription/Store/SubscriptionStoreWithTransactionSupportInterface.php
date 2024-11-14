<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Subscription\Store;

use Neos\ContentRepository\Core\Subscription\Subscription;
use Neos\ContentRepository\Core\Subscription\SubscriptionId;
use Neos\ContentRepository\Core\Subscription\Subscriptions;

/**
 * @api
 */
interface SubscriptionStoreWithTransactionSupportInterface extends SubscriptionStoreInterface
{
    /**
     * @template T
     * @param \Closure():T $closure
     * @return T
     */
    public function transactional(\Closure $closure): mixed;
}
