<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Subscription\Engine;

/**
 * @implements \IteratorAggregate<Error>
 * @internal implementation detail of the catchup
 */
final readonly class Errors implements \IteratorAggregate, \Countable
{
    /**
     * @var non-empty-array<Error>
     */
    private array $errors;

    private function __construct(
        Error ...$errors
    ) {
        if ($errors === []) {
            throw new \InvalidArgumentException('Errors must not be empty.', 1731612542);
        }
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

    public function count(): int
    {
        return count($this->errors);
    }

    public function first(): Error
    {
        foreach ($this->errors as $error) {
            return $error;
        }
    }
}
