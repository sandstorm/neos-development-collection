<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Subscription\Engine;

/**
 * @internal
 */
final class Result
{
    /** @param list<Error> $errors */
    public function __construct(
        public readonly array $errors = [],
    ) {
    }
}
