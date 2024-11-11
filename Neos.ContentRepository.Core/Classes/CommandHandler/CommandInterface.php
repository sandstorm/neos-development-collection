<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\CommandHandler;

/**
 * Common interface for all commands of the Content Repository
 *
 * @internal because extra commands are no extension point
 */
interface CommandInterface
{
    /**
     * @param array<string,mixed> $array
     */
    public static function fromArray(array $array): self;
}
