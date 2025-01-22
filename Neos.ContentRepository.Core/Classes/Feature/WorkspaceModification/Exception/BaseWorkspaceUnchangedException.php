<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Feature\WorkspaceModification\Exception;

use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;

/**
 * Exception when attempting to change the base workspace to the currently set base workspace
 *
 * The case is not handled gracefully with a no-op as there would be no traces (emitted events) of the handled command.
 * This exception denoting the operation is obsolete hardens the interaction.
 *
 * @api
 */
final class BaseWorkspaceUnchangedException extends \RuntimeException
{
    public static function becauseTheAttemptedBaseWorkspaceIsTheBase(WorkspaceName $attemptedBaseWorkspaceName, WorkspaceName $workspaceName): self
    {
        return new self(sprintf('Skipped changing the base workspace to "%s" from workspace "%s" because its already set.', $attemptedBaseWorkspaceName->value, $workspaceName->value), 1737534132);
    }
}
