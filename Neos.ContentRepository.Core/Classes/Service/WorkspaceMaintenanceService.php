<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Service;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceInterface;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Command\RebaseWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Dto\RebaseErrorHandlingStrategy;
use Neos\ContentRepository\Core\SharedModel\Workspace\Workspace;
use Neos\ContentRepository\Core\SharedModel\Workspace\Workspaces;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceStatus;

/**
 * @api
 */
class WorkspaceMaintenanceService implements ContentRepositoryServiceInterface
{
    public function __construct(
        private readonly ContentRepository $contentRepository
    ) {
    }

    /**
     * @return Workspaces the workspaces of the removed content streams
     */
    public function rebaseOutdatedWorkspaces(?RebaseErrorHandlingStrategy $strategy = null): Workspaces
    {
        $outdatedWorkspaces = $this->contentRepository->findWorkspaces()->filter(
            fn (Workspace $workspace) => $workspace->status === WorkspaceStatus::OUTDATED
        );
        // todo we need to loop through the workspaces from root level first
        foreach ($outdatedWorkspaces as $workspace) {
            if ($workspace->status !== WorkspaceStatus::OUTDATED) {
                continue;
            }
            $rebaseCommand = RebaseWorkspace::create(
                $workspace->workspaceName,
            );
            if ($strategy) {
                $rebaseCommand = $rebaseCommand->withErrorHandlingStrategy($strategy);
            }
            $this->contentRepository->handle($rebaseCommand);
        }

        return $outdatedWorkspaces;
    }
}
