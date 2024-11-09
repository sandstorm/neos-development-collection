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

use Neos\ContentRepository\Core\Feature\WorkspaceRebase\EventsThatFailedDuringRebase;

/**
 * @api this exception contains information about what exactly went wrong during rebase
 */
final class WorkspaceRebaseFailed extends \Exception
{
    private function __construct(
        public readonly EventsThatFailedDuringRebase $eventsThatFailedDuringRebase,
        string $message,
        int $code,
        ?\Throwable $previous,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function duringRebase(EventsThatFailedDuringRebase $eventsThatFailedDuringRebase): self
    {
        return new self(
            $eventsThatFailedDuringRebase,
            sprintf('Rebase failed: %s', self::renderMessage($eventsThatFailedDuringRebase)),
            1729974936,
            $eventsThatFailedDuringRebase->first()?->getException()
        );
    }

    public static function duringPublish(EventsThatFailedDuringRebase $eventsThatFailedDuringRebase): self
    {
        return new self(
            $eventsThatFailedDuringRebase,
            sprintf('Publication failed: %s', self::renderMessage($eventsThatFailedDuringRebase)),
            1729974980,
            $eventsThatFailedDuringRebase->first()?->getException()
        );
    }

    public static function duringDiscard(EventsThatFailedDuringRebase $eventsThatFailedDuringRebase): self
    {
        return new self(
            $eventsThatFailedDuringRebase,
            sprintf('Discard failed: %s', self::renderMessage($eventsThatFailedDuringRebase)),
            1729974982,
            $eventsThatFailedDuringRebase->first()?->getException()
        );
    }

    private static function renderMessage(EventsThatFailedDuringRebase $eventsThatFailedDuringRebase): string
    {
        $firstFailure = $eventsThatFailedDuringRebase->first();
        return sprintf('"%s" and %d further failures', $firstFailure?->getException()->getMessage(), count($eventsThatFailedDuringRebase) - 1);
    }
}
