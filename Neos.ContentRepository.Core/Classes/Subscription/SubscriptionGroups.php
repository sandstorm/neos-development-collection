<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Subscription;

/**
 * @implements \IteratorAggregate<SubscriptionGroup>
 * @internal
 */
final class SubscriptionGroups implements \IteratorAggregate, \Countable, \JsonSerializable
{
    /**
     * @param array<string, SubscriptionGroup> $groupsByValue
     */
    private function __construct(
        private readonly array $groupsByValue
    ) {
    }

    /**
     * @param list<SubscriptionGroup|string> $groups
     */
    public static function fromArray(array $groups): self
    {
        $groupsByValue = [];
        foreach ($groups as $group) {
            if (is_string($group)) {
                $group = SubscriptionGroup::fromString($group);
            }
            if (!$group instanceof SubscriptionGroup) {
                throw new \InvalidArgumentException(sprintf('Expected instance of %s, got: %s', SubscriptionGroup::class, get_debug_type($group)), 1731580587);
            }
            if (array_key_exists($group->value, $groupsByValue)) {
                throw new \InvalidArgumentException(sprintf('Group "%s" is already part of this set', $group->value), 1731580633);
            }
            $groupsByValue[$group->value] = $group;
        }
        return new self($groupsByValue);
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function getIterator(): \Traversable
    {
        yield from array_values($this->groupsByValue);
    }

    public function count(): int
    {
        return count($this->groupsByValue);
    }

    public function contain(SubscriptionGroup $group): bool
    {
        return array_key_exists($group->value, $this->groupsByValue);
    }

    /**
     * @return list<string>
     */
    public function toStringArray(): array
    {
        return array_values(array_map(static fn (SubscriptionGroup $group) => $group->value, $this->groupsByValue));
    }

    /**
     * @return iterable<mixed>
     */
    public function jsonSerialize(): iterable
    {
        return array_values($this->groupsByValue);
    }
}
