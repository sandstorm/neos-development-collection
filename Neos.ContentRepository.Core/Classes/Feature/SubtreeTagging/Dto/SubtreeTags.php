<?php

/*
 * This file is part of the Neos.ContentRepository package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Feature\SubtreeTagging\Dto;

/**
 * A type-safe collection of {@see SubtreeTag} instances
 *
 * @api
 * @implements \IteratorAggregate<SubtreeTag>
 */
final readonly class SubtreeTags implements \IteratorAggregate, \Countable, \JsonSerializable
{
    /**
     * @var array<string, SubtreeTag>
     */
    private array $tags;

    private function __construct(SubtreeTag ...$tags)
    {
        $tagsByValue = [];
        foreach ($tags as $tag) {
            $tagsByValue[$tag->value] = $tag;
        }
        $this->tags = $tagsByValue;
    }

    public static function createEmpty(): self
    {
        return new self();
    }

    /**
     * @param array<SubtreeTag> $tags
     */
    public static function fromArray(array $tags): self
    {
        return new self(...$tags);
    }

    public static function fromStrings(string ...$tags): self
    {
        return new self(...array_map(SubtreeTag::fromString(...), $tags));
    }

    public function with(SubtreeTag $subtreeTagToAdd): self
    {
        if ($this->contain($subtreeTagToAdd)) {
            return $this;
        }
        return new self(...[...$this->tags, $subtreeTagToAdd]);
    }

    public function without(SubtreeTag $subtreeTagToRemove): self
    {
        if (!$this->contain($subtreeTagToRemove)) {
            return $this;
        }
        return new self(...array_filter($this->tags, static fn (SubtreeTag $tag) => !$tag->equals($subtreeTagToRemove)));
    }

    public function isEmpty(): bool
    {
        return $this->tags === [];
    }

    public function count(): int
    {
        return count($this->tags);
    }

    public function contain(SubtreeTag $tag): bool
    {
        return array_key_exists($tag->value, $this->tags);
    }

    public function intersection(self $other): self
    {
        return self::fromArray(array_intersect_key($this->tags, $other->tags));
    }

    public function difference(self $other): self
    {
        return self::fromArray(array_diff_key($this->tags, $other->tags));
    }

    public function merge(self $other): self
    {
        return self::fromArray(array_merge($this->tags, $other->tags));
    }

    /**
     * @template T
     * @param \Closure(SubtreeTag $tag): T $callback
     * @return list<T>
     */
    public function map(\Closure $callback): array
    {
        return array_map($callback, array_values($this->tags));
    }

    /**
     * @return array<string>
     */
    public function toStringArray(): array
    {
        return $this->map(static fn (SubtreeTag $tag) => $tag->value);
    }

    public function equals(SubtreeTags $other): bool
    {
        return count($this->tags) === count($other->tags) && array_diff_key($this->tags, $other->tags) === [];
    }

    public function getIterator(): \Traversable
    {
        yield from array_values($this->tags);
    }

    /**
     * @return array<SubtreeTag>
     */
    public function jsonSerialize(): array
    {
        return array_values($this->tags);
    }
}
