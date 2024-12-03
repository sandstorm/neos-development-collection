<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Projection\CatchUpHook;

/**
 * Thrown if a delegated catchup hook fails
 *
 * @implements \IteratorAggregate<\Throwable>
 * @api
 */
final class CatchUpHookFailed extends \RuntimeException implements \IteratorAggregate
{
    /**
     * @internal
     * @param array<\Throwable> $additionalExceptions
     */
    public function __construct(
        string $message,
        int $code,
        \Throwable $exception,
        private readonly array $additionalExceptions
    ) {
        parent::__construct($message, $code, $exception);
    }

    public function getIterator(): \Traversable
    {
        $previous = $this->getPrevious();
        if ($previous !== null) {
            yield $previous;
        }
        yield from $this->additionalExceptions;
    }
}
