<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Service;

use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceInterface;
use Neos\ContentRepository\Core\Subscription\Engine\SubscriptionEngine;
use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Model\EventStore\Status;

/**
 * @api
 */
final readonly class SubscriptionService implements ContentRepositoryServiceInterface
{
    public function __construct(
        private EventStoreInterface $eventStore,
        public SubscriptionEngine $subscriptionEngine,
    ) {
    }

    public function setupEventStore(): void
    {
        $this->eventStore->setup();
    }

    public function eventStoreStatus(): Status
    {
        return $this->eventStore->status();
    }
}
