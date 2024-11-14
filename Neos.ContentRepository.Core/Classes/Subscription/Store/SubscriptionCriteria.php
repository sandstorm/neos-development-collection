<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Subscription\Store;

use Neos\ContentRepository\Core\Subscription\Engine\SubscriptionEngineCriteria;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\ContentRepository\Core\Subscription\SubscriptionGroups;
use Neos\ContentRepository\Core\Subscription\SubscriptionId;
use Neos\ContentRepository\Core\Subscription\SubscriptionIds;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatusFilter;

/**
 * @api
 */
final readonly class SubscriptionCriteria
{
    private function __construct(
        public SubscriptionIds|null $ids,
        public SubscriptionGroups|null $groups,
        public SubscriptionStatusFilter $status,
    ) {
    }

    /**
     * @param SubscriptionIds|array<string|SubscriptionId>|null $ids
     * @param SubscriptionGroups|list<string>|null $groups
     * @param SubscriptionStatusFilter|null $status
     */
    public static function create(
        SubscriptionIds|array $ids = null,
        SubscriptionGroups|array $groups = null,
        SubscriptionStatusFilter $status = null,
    ): self {
        if (is_array($ids)) {
            $ids = SubscriptionIds::fromArray($ids);
        }
        if (is_array($groups)) {
            $groups = SubscriptionGroups::fromArray($groups);
        }
        return new self(
            $ids,
            $groups,
            $status ?? SubscriptionStatusFilter::any(),
        );
    }

    public static function forEngineCriteriaAndStatus(
        SubscriptionEngineCriteria $criteria,
        SubscriptionStatusFilter|SubscriptionStatus $status,
    ): self {
        if ($status instanceof SubscriptionStatus) {
            $status = SubscriptionStatusFilter::fromArray([$status]);
        }
        return new self(
            $criteria->ids,
            $criteria->groups,
            $status,
        );
    }

    public static function noConstraints(): self
    {
        return new self(
            ids: null,
            groups: null,
            status: SubscriptionStatusFilter::any(),
        );
    }
}
