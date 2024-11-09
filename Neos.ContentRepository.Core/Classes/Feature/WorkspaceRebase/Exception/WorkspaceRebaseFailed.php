<?php

/*
 * This file is part of the Neos.ContentRepository.Core package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Feature\WorkspaceRebase\Exception;

use Neos\ContentRepository\Core\Feature\WorkspaceRebase\CommandsThatFailedDuringRebase;

/**
 * @api this exception contains information about what exactly went wrong during rebase
 */
final class WorkspaceRebaseFailed extends \Exception
{
    private function __construct(
        public readonly CommandsThatFailedDuringRebase $commandsThatFailedDuringRebase,
        string $message,
        int $code,
        ?\Throwable $previous,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function duringRebase(CommandsThatFailedDuringRebase $commandsThatFailedDuringRebase): self
    {
        return new self(
            $commandsThatFailedDuringRebase,
            sprintf('Rebase failed: %s', self::renderMessage($commandsThatFailedDuringRebase)),
            1729974936,
            $commandsThatFailedDuringRebase->first()?->getException()
        );
    }

    public static function duringPublish(CommandsThatFailedDuringRebase $commandsThatFailedDuringRebase): self
    {
        return new self(
            $commandsThatFailedDuringRebase,
            sprintf('Publication failed: %s', self::renderMessage($commandsThatFailedDuringRebase)),
            1729974980,
            $commandsThatFailedDuringRebase->first()?->getException()
        );
    }

    public static function duringDiscard(CommandsThatFailedDuringRebase $commandsThatFailedDuringRebase): self
    {
        return new self(
            $commandsThatFailedDuringRebase,
            sprintf('Discard failed: %s', self::renderMessage($commandsThatFailedDuringRebase)),
            1729974982,
            $commandsThatFailedDuringRebase->first()?->getException()
        );
    }

    private static function renderMessage(CommandsThatFailedDuringRebase $commandsThatFailedDuringRebase): string
    {
        $firstFailure = $commandsThatFailedDuringRebase->first();
        return sprintf('"%s" and %d further failures', $firstFailure?->getException()->getMessage(), count($commandsThatFailedDuringRebase) - 1);
    }
}
