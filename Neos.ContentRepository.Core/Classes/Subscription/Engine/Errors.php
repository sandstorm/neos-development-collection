<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Subscription\Engine;

/**
 * @implements \IteratorAggregate<Error>
 */
final readonly class Errors implements \IteratorAggregate, \Countable
{
    /**
     * array<Error>
     */
    private array $errors;

    /**
     * @param array<Error> $errors
     */
    private function __construct(
        Error ...$errors
    ) {
        $this->errors = $errors;
    }

    /**
     * @param array<Error> $errors
     */
    public static function fromArray(array $errors): self
    {
        return new self(...$errors);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->errors;
    }

    public function isEmpty(): bool
    {
        return $this->errors === [];
    }

    public function count(): int
    {
        return count($this->errors);
    }
}
