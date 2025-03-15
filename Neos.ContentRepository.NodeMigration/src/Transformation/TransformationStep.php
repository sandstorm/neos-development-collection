<?php

declare(strict_types=1);

namespace Neos\ContentRepository\NodeMigration\Transformation;

use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\CommandHandler\Commands;

final class TransformationStep
{
    private function __construct(
        public Commands $commands,
        // todo public bool $requireConfirmation
    ) {
    }

    public static function createEmpty(): self
    {
        return new self(Commands::createEmpty());
    }

    public static function fromCommand(CommandInterface $command): self
    {
        return new self(Commands::create($command));
    }

    public static function fromCommands(Commands $commands): self
    {
        return new self($commands);
    }
}
