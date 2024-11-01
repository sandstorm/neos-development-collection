<?php

/*
 * This file is part of the Neos.ContentRepository package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Feature\WorkspacePublication\Exception;

use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;

/**
 * @api thrown as part of command handling in case of empty publish and rebase operations
 */
class NoChangesException extends \RuntimeException
{
    public static function inWorkspaceToPublish(WorkspaceName $workspaceName): self
    {
        return new self(sprintf('Cannot publish workspace "%s" without any changes', $workspaceName->value), 1730463156);
    }

    public static function nothingSelectedForPublish(WorkspaceName $workspaceName): self
    {
        return new self(sprintf('Cannot publish workspace "%s" because no changes were selected.', $workspaceName->value), 1730463510);
    }

    public static function noChangesToRebase(WorkspaceName $workspaceName): self
    {
        return new self(sprintf('Cannot rebase workspace "%s" because it has no changes.', $workspaceName->value), 1730463693);
    }
}
