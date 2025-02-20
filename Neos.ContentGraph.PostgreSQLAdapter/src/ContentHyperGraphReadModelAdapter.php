<?php

declare(strict_types=1);

namespace Neos\ContentGraph\PostgreSQLAdapter;

use Doctrine\DBAL\Connection;
use Neos\ContentGraph\PostgreSQLAdapter\Domain\Repository\ContentHypergraph;
use Neos\ContentGraph\PostgreSQLAdapter\Domain\Repository\NodeFactory;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphReadModelInterface;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Exception\WorkspaceDoesNotExist;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStream;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\Workspace;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Core\SharedModel\Workspace\Workspaces;

/**
 * @internal
 */
final readonly class ContentHyperGraphReadModelAdapter implements ContentGraphReadModelInterface
{
    public function __construct(
        private Connection $dbal,
        private NodeFactory $nodeFactory,
        private ContentRepositoryId $contentRepositoryId,
        private NodeTypeManager $nodeTypeManager,
        private string $tableNamePrefix,
    ) {
    }

    public function getContentGraph(WorkspaceName $workspaceName): ContentGraphInterface
    {
        $contentStreamId = $this->findWorkspaceByName($workspaceName)?->currentContentStreamId;
        if ($contentStreamId === null) {
            throw WorkspaceDoesNotExist::butWasSupposedTo($workspaceName);
        }
        return new ContentHyperGraph($this->dbal, $this->nodeFactory, $this->contentRepositoryId, $this->nodeTypeManager, $this->tableNamePrefix, $workspaceName, $contentStreamId);
    }

    public function findWorkspaceByName(WorkspaceName $workspaceName): ?Workspace
    {
        // TODO: Implement findWorkspaceByName() method.
        return null;
    }

    public function findWorkspaces(): Workspaces
    {
        // TODO: Implement getWorkspaces() method.
        return Workspaces::createEmpty();
    }

    public function findContentStreamById(ContentStreamId $contentStreamId): ?ContentStream
    {
        // TODO: Implement findContentStreamById() method.
        return null;
    }

    public function countNodes(): int
    {
        // TODO: Implement countNodes method.
        return 0;
    }
}
