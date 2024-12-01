<?php

declare(strict_types=1);

namespace Neos\ContentRepositoryRegistry\Command;

use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\ContentRepositoryRegistry\Service\EventMigrationServiceFactory;
use Neos\Flow\Cli\CommandController;

class MigrateEventsCommandController extends CommandController
{
    public function __construct(
        private readonly ContentRepositoryRegistry $contentRepositoryRegistry,
        private readonly EventMigrationServiceFactory $eventMigrationServiceFactory
    ) {
        parent::__construct();
    }

    /**
     * Temporary low level backup to ensure the prune migration https://github.com/neos/neos-development-collection/pull/5297 is safe
     *
     * @param string $contentRepository Identifier of the Content Repository to backup
     */
    public function backupCommand(string $contentRepository = 'default'): void
    {
        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $eventMigrationService = $this->contentRepositoryRegistry->buildService($contentRepositoryId, $this->eventMigrationServiceFactory);
        $eventMigrationService->backup($this->outputLine(...));
    }

    /**
     * Migrates initial metadata & roles from the CR core workspaces to the corresponding Neos database tables
     *
     * Needed to extract these information to Neos.Neos: https://github.com/neos/neos-development-collection/issues/4726
     *
     * Included in September 2024 - before final Neos 9.0 release
     *
     * @param string $contentRepository Identifier of the Content Repository to migrate
     */
    public function migrateWorkspaceMetadataToWorkspaceServiceCommand(string $contentRepository = 'default'): void
    {
        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $eventMigrationService = $this->contentRepositoryRegistry->buildService($contentRepositoryId, $this->eventMigrationServiceFactory);
        $eventMigrationService->migrateWorkspaceMetadataToWorkspaceService($this->outputLine(...));
    }

    /**
     * Migrates "propertyValues":{"tagName":{"value":null,"type":"string"}} to "propertiesToUnset":["tagName"]
     *
     * Needed for #4322: https://github.com/neos/neos-development-collection/pull/4322
     *
     * Included in February 2024 - before final Neos 9.0 release
     *
     * @param string $contentRepository Identifier of the Content Repository to migrate
     */
    public function migratePropertiesToUnsetCommand(string $contentRepository = 'default'): void
    {
        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $eventMigrationService = $this->contentRepositoryRegistry->buildService($contentRepositoryId, $this->eventMigrationServiceFactory);
        $eventMigrationService->migratePropertiesToUnset($this->outputLine(...));
    }

    /**
     * Adds a dummy workspace name to the events meta-data, so it can be rebased
     *
     * Needed for #4708: https://github.com/neos/neos-development-collection/pull/4708
     *
     * Included in March 2024 - before final Neos 9.0 release
     *
     * @param string $contentRepository Identifier of the Content Repository to migrate
     */
    public function migrateMetaDataToWorkspaceNameCommand(string $contentRepository = 'default'): void
    {
        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $eventMigrationService = $this->contentRepositoryRegistry->buildService($contentRepositoryId, $this->eventMigrationServiceFactory);
        $eventMigrationService->migrateMetaDataToWorkspaceName($this->outputLine(...));
    }

    /**
     * Adds the "workspaceName" to the data of all content stream related events
     *
     * Needed for feature "Add workspaceName to relevant events": https://github.com/neos/neos-development-collection/issues/4996
     *
     * Included in May 2024 - before final Neos 9.0 release
     *
     * @param string $contentRepository Identifier of the Content Repository to migrate
     */
    public function migratePayloadToWorkspaceNameCommand(string $contentRepository = 'default'): void
    {
        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $eventMigrationService = $this->contentRepositoryRegistry->buildService($contentRepositoryId, $this->eventMigrationServiceFactory);
        $eventMigrationService->migratePayloadToWorkspaceName($this->outputLine(...));
    }

    /**
     * Rewrites all workspaceNames, that are not matching new constraints.
     *
     * Needed for feature "Stabilize WorkspaceName value object": https://github.com/neos/neos-development-collection/pull/5193
     *
     * Included in August 2024 - before final Neos 9.0 release
     *
     * @param string $contentRepository Identifier of the Content Repository to migrate
     */
    public function migratePayloadToValidWorkspaceNamesCommand(string $contentRepository = 'default'): void
    {
        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $eventMigrationService = $this->contentRepositoryRegistry->buildService($contentRepositoryId, $this->eventMigrationServiceFactory);
        $eventMigrationService->migratePayloadToValidWorkspaceNames($this->outputLine(...));
    }

    public function migrateSetReferencesToMultiNameFormatCommand(string $contentRepository = 'default'): void
    {
        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $eventMigrationService = $this->contentRepositoryRegistry->buildService($contentRepositoryId, $this->eventMigrationServiceFactory);
        $eventMigrationService->migrateReferencesToMultiFormat($this->outputLine(...));
    }

    /**
     * Reorders all NodeAggregateWasMoved events to allow replaying in case orphaned nodes existed in previous betas
     *
     * Fixes these bugs to allow to migrate to Beta 15:
     *
     * - #5364 https://github.com/neos/neos-development-collection/issues/5364
     * - #5352 https://github.com/neos/neos-development-collection/issues/5352
     *
     * Included in November 2024 - before final Neos 9.0 release
     */
    public function reorderNodeAggregateWasRemovedCommand(string $contentRepository = 'default'): void
    {
        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $eventMigrationService = $this->contentRepositoryRegistry->buildService($contentRepositoryId, $this->eventMigrationServiceFactory);
        $eventMigrationService->reorderNodeAggregateWasRemoved($this->outputLine(...));
    }

    /**
     * Migrates "nodeAggregateClassification":"tethered" to "regular", in case for copied tethered nodes.
     *
     * Needed for #5350: https://github.com/neos/neos-development-collection/issues/5350
     *
     * Included in November 2024 - before final Neos 9.0 release
     *
     * @param string $contentRepository Identifier of the Content Repository to migrate
     */
    public function migrateCopyTetheredNodeCommand(string $contentRepository = 'default'): void
    {
        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $eventMigrationService = $this->contentRepositoryRegistry->buildService($contentRepositoryId, $this->eventMigrationServiceFactory);
        $eventMigrationService->migrateCopyTetheredNode($this->outputLine(...));
    }

    /**
     * Status information if content streams still contain legacy copy node events
     *
     * Needed for #5371: https://github.com/neos/neos-development-collection/pull/5371
     *
     * Included in November 2024 - before final Neos 9.0 release
     *
     * NOTE: To reduce the number of matched content streams and to cleanup the event store run
     * `./flow contentStream:removeDangling` and `./flow contentStream:pruneRemovedFromEventStream`
     *
     * @param string $contentRepository Identifier of the Content Repository to check
     */
    public function copyNodesStatusCommand(string $contentRepository = 'default'): void
    {
        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $eventMigrationService = $this->contentRepositoryRegistry->buildService($contentRepositoryId, $this->eventMigrationServiceFactory);
        $eventMigrationService->copyNodesStatus($this->outputLine(...));
    }

    /**
     * Renames partial publish and discard events to a publish or discard
     *
     * Needed for BUGFIX: Simplify PartialPublish & Discard: https://github.com/neos/neos-development-collection/pull/5385
     *
     * Both events share the same properties their counter, parts have.
     * Well keep the $publishedNodes and $discardedNodes fields in the database as they don't do harm.
     *
     * Included in November 2024 - before final Neos 9.0 release
     *
     * This migration is only required in case you want to replay. It does not fix anything.
     *
     * @param string $contentRepository Identifier of the Content Repository to migrate
     */
    public function migratePartialPublishAndPartialDiscardEventsCommand(string $contentRepository = 'default'): void
    {
        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $eventMigrationService = $this->contentRepositoryRegistry->buildService($contentRepositoryId, $this->eventMigrationServiceFactory);
        $eventMigrationService->migratePartialPublishAndPartialDiscardEvents($this->outputLine(...));
    }
}
