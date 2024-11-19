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

use Neos\ContentRepository\Core\Feature\WorkspaceRebase\ConflictingEvents;

/**
 * @api this exception contains information about what exactly went wrong during rebase
 */
final class WorkspaceRebaseFailed extends \Exception
{
    private function __construct(
        public readonly ConflictingEvents $conflictingEvents,
        string $message,
        int $code,
        ?\Throwable $previous,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function duringRebase(ConflictingEvents $conflictingEvents): self
    {
        return new self(
            $conflictingEvents,
            sprintf('Rebase failed: %s', self::renderMessage($conflictingEvents)),
            1729974936,
            $conflictingEvents->first()?->getException()
        );
    }

    public static function duringPublish(ConflictingEvents $conflictingEvents): self
    {
        return new self(
            $conflictingEvents,
            sprintf('Publication failed: %s', self::renderMessage($conflictingEvents)),
            1729974980,
            $conflictingEvents->first()?->getException()
        );
    }

    public static function duringDiscard(ConflictingEvents $conflictingEvents): self
    {
        return new self(
            $conflictingEvents,
            sprintf('Discard failed: %s', self::renderMessage($conflictingEvents)),
            1729974982,
            $conflictingEvents->first()?->getException()
        );
    }

    private static function renderMessage(ConflictingEvents $conflictingEvents): string
    {
        $firstConflict = $conflictingEvents->first();
        return sprintf('"%s" and %d further conflicts', $firstConflict?->getException()->getMessage(), count($conflictingEvents) - 1);
    }
}
