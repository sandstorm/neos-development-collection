<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Feature\WorkspaceModification\Exception;

/**
 * @api thrown in case a base workspace change is attempted while the workspace still has pending changes
 */
final class WorkspaceIsNotEmptyException extends \RuntimeException
{
}
