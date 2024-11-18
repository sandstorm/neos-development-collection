<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Subscription;

/**
 * @api
 * @implements \IteratorAggregate<SubscriptionAndProjectionStatus>
 */
final readonly class SubscriptionAndProjectionStatuses implements \IteratorAggregate
{
    /**
     * @var array<SubscriptionAndProjectionStatus> $statuses
     */
    private array $statuses;

    private function __construct(
        SubscriptionAndProjectionStatus ...$statuses,
    ) {
        $this->statuses = $statuses;
    }

    /**
     * @param array<SubscriptionAndProjectionStatus> $statuses
     */
    public static function fromArray(array $statuses): self
    {
        return new self(...$statuses);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->statuses;
    }

    public function isEmpty(): bool
    {
        return $this->statuses === [];
    }
}
