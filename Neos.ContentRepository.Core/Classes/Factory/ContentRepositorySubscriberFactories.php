<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Factory;

/**
 * @implements \IteratorAggregate<ContentRepositorySubscriberFactoryInterface>
 */
final class ContentRepositorySubscriberFactories implements \IteratorAggregate
{
    private array $subscriberFactories;

    private function __construct(ContentRepositorySubscriberFactoryInterface ...$subscriberFactories)
    {
        $this->subscriberFactories = $subscriberFactories;
    }

    public static function fromArray(array $subscriberFactories): self
    {
        return new self(...$subscriberFactories);
    }

    public static function none(): self
    {
        return new self();
    }

    public function isEmpty(): bool
    {
        return $this->subscriberFactories === [];
    }

    public function getIterator(): \Traversable
    {
        yield from $this->subscriberFactories;
    }
}
