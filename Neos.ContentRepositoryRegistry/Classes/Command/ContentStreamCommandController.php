<?php

declare(strict_types=1);

namespace Neos\ContentRepositoryRegistry\Command;

use Neos\ContentRepository\Core\Service\ContentStreamPrunerFactory;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;

class ContentStreamCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var ContentRepositoryRegistry
     */
    protected $contentRepositoryRegistry;

    /**
     * @param string $contentRepository Identifier of the content repository. (Default: 'default')
     */
    public function statusCommand(string $contentRepository = 'default'): void
    {
        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $contentStreamPruner = $this->contentRepositoryRegistry->buildService($contentRepositoryId, new ContentStreamPrunerFactory());

        $status = $contentStreamPruner->status(
            $this->outputLine(...)
        );
        if ($status === false) {
            $this->quit(1);
        }
    }

    /**
     * Before Neos 9 beta 15 (#5301), dangling content streams were not removed during publishing, discard or rebase.
     *
     * Removes all nodes, hierarchy relations and content stream entries which are not needed anymore from the projections.
     *
     * NOTE: This still **keeps** the event stream as is; so it would be possible to re-construct the content stream at a later point in time.
     *
     * To prune the removed content streams from the event store, call ./flow contentStream:pruneRemovedFromEventStream afterwards.
     *
     * @param string $contentRepository Identifier of the content repository. (Default: 'default')
     * @param string $removeTemporaryBefore includes all temporary content streams like FORKED or CREATED older than that in the removal
     */
    public function removeDanglingCommand(string $contentRepository = 'default', string $removeTemporaryBefore = '-1day'): void
    {
        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $contentStreamPruner = $this->contentRepositoryRegistry->buildService($contentRepositoryId, new ContentStreamPrunerFactory());

        $contentStreamPruner->removeDanglingContentStreams(
            $this->outputLine(...),
            new \DateTimeImmutable($removeTemporaryBefore)
        );
    }

    /**
     * Remove unused and deleted content streams from the event stream; effectively REMOVING information completely
     *
     * @param string $contentRepository Identifier of the content repository. (Default: 'default')
     */
    public function pruneRemovedFromEventStreamCommand(string $contentRepository = 'default'): void
    {
        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $contentStreamPruner = $this->contentRepositoryRegistry->buildService($contentRepositoryId, new ContentStreamPrunerFactory());

        $contentStreamPruner->pruneRemovedFromEventStream(
            $this->outputLine(...)
        );
    }
}
