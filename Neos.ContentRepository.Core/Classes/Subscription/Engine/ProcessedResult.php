<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Subscription\Engine;

/**
 * @api
 */
final readonly class ProcessedResult
{
    private function __construct(
        public int $numberOfProcessedEvents,
        public Errors|null $errors,
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

    /** @phpstan-assert-if-true !null $this->errors */
    public function hasFailed(): bool
    {
        return $this->errors !== null;
    }

    public function throwOnFailure(): void
    {
        /** @var Error[] $errors */
        $errors = iterator_to_array($this->errors ?? []);
        if ($errors === []) {
            return;
        }
        $firstError = array_shift($errors);

        $additionalFailedSubscribers = array_map(fn (Error $error) => $error->subscriptionId->value, $errors);

        $additionalErrors = $additionalFailedSubscribers === [] ? '' : sprintf(' And subscribers %s with additional errors.', join(', ', $additionalFailedSubscribers));
        $exceptionMessage = sprintf('Exception in subscriber "%s" while catching up: %s.%s', $firstError->subscriptionId->value, $firstError->message, $additionalErrors);

        // todo custom exception!
        throw new \RuntimeException($exceptionMessage, 1732132930, $firstError->throwable);
    }
}
