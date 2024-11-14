<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Subscription\Engine;

/**
 * @api
 */
final readonly class ProcessedResult
{
    private function __construct(
        public readonly int $numberOfProcessedEvents,
        public readonly Errors|null $errors,
    ) {
    }

    public static function success(int $numberOfProcessedEvents): self
    {
        return new self($numberOfProcessedEvents, null);
    }

    public static function failed(int $numberOfProcessedEvents, Errors $errors): self
    {
        return new self($numberOfProcessedEvents, $errors);
    }

    public function hasErrors(): bool
    {
        return $this->errors !== null;
    }
}
