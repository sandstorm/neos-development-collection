<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\CommandHandler;

/**
 * Common (marker) interface for all commands that need to be serialized for rebasing
 *
 * During a rebase, the command (either {@see CommandInterface} or this serialized counterpart) will be deserialized
 * from array {@see SerializedCommandInterface::fromArray()} and reapplied {@see CommandSimulator}
 *
 * @internal
 */
interface SerializedCommandInterface
{
    /**
     * called during deserialization from metadata
     * @param array<string,mixed> $array
     */
    public static function fromArray(array $array): self;
}
