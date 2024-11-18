<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Subscription\Engine;

/**
 * @implements \IteratorAggregate<Error>
 * @api
 */
final readonly class Errors implements \IteratorAggregate, \Countable
{
    /**
     * @var array<Error>
     */
    private array $errors;

    private function __construct(
        Error ...$errors
    ) {
        $this->errors = $errors;
        if ($this->errors === []) {
            throw new \InvalidArgumentException('Errors must not be empty.', 1731612542);
        }
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

    public function count(): int
    {
        return count($this->errors);
    }
}
