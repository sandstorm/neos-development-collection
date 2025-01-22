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

use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateIds;
use Neos\ContentRepository\Core\SharedModel\Workspace\Workspace;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;

/**
 * Exception to denote that the workspace rebase operation was skipped as there are no (selected) publishable changes.
 *
 * The case is not handled gracefully with a no-op as there would be no traces (emitted events) of the handled command,
 * and the original content stream id is kept. This exception denoting the operation is obsolete hardens the interactions.
 *
 * To avoid this exception being thrown into the outer world it should be caught and logged or the case were its
 * likely that the operation might be a noop can easily be determined beforehand via {@see Workspace::hasPublishableChanges()}:
 *
 *     if ($workspace->hasPublishableChanges()) {
 *         $contentRepository->handle(...);
 *     }
 *
 * @api thrown as part of command handling in case of empty publish, discard and rebase operations
 */
class NoChangesException extends \RuntimeException
{
    public static function becauseWorkspaceToPublishIsEmpty(WorkspaceName $workspaceName): self
    {
        return new self(sprintf('Skipped publish workspace "%s" without any publishable changes.', $workspaceName->value), 1730463156);
    }

    public static function becauseWorkspaceToDiscardIsEmpty(WorkspaceName $workspaceName): self
    {
        return new self(sprintf('Skipped discard workspace "%s" without any publishable changes.', $workspaceName->value), 1730463156);
    }

    public static function becauseWorkspaceToRebaseIsNotOutdated(WorkspaceName $workspaceName): self
    {
        return new self(sprintf('Skipped rebase workspace "%s" because it is not outdated.', $workspaceName->value), 1730463693);
    }

    public static function becauseFilterDidNotMatch(WorkspaceName $workspaceName, NodeAggregateIds $selectedNodeAggregateIds): self
    {
        return new self(sprintf('No nodes matched in workspace "%s" the filter %s.', $workspaceName->value, join(',', $selectedNodeAggregateIds->toStringArray())), 1737477674);
    }
}
