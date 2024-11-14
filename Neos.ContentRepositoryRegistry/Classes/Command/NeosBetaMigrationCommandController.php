<?php

declare(strict_types=1);

namespace Neos\ContentRepositoryRegistry\Command;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryDependencies;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryInterface;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceInterface;
use Neos\ContentRepository\Core\Feature\ContentStreamEventStreamName;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\ContentRepositoryRegistry\Factory\EventStore\DoctrineEventStoreFactory;
use Neos\EventStore\Model\Event;
use Neos\EventStore\Model\Event\EventMetadata;
use Neos\EventStore\Model\Event\EventType;
use Neos\EventStore\Model\Event\EventTypes;
use Neos\EventStore\Model\EventEnvelope;
use Neos\EventStore\Model\Events;
use Neos\EventStore\Model\EventStream\EventStreamFilter;
use Neos\EventStore\Model\EventStream\ExpectedVersion;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;

class NeosBetaMigrationCommandController extends CommandController
{
    #[Flow\Inject()]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject()]
    protected Connection $connection;

    public function reorderNodeAggregateWasRemovedCommand(string $contentRepository = 'default', string $workspaceName = 'live'): void
    {
        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $this->backup($contentRepositoryId);

        $workspace = $this->contentRepositoryRegistry->get($contentRepositoryId)->findWorkspaceByName(WorkspaceName::fromString($workspaceName));
        if (!$workspace) {
            $this->outputLine('Workspace not found');
            $this->quit(1);
        }

        $streamName = ContentStreamEventStreamName::fromContentStreamId($workspace->currentContentStreamId)->getEventStreamName();

        $internals = $this->getInternals($contentRepositoryId);

        // get all NodeAggregateWasRemoved from the content stream
        $eventsToReorder = iterator_to_array($internals->eventStore->load($streamName, EventStreamFilter::create(EventTypes::create(EventType::fromString('NodeAggregateWasRemoved')))), false);

        // remove all the NodeAggregateWasRemoved events at their sequenceNumbers
        $eventTableName = DoctrineEventStoreFactory::databaseTableName($contentRepositoryId);
        $this->connection->beginTransaction();
        $this->connection->executeStatement(
            'DELETE FROM ' . $eventTableName . ' WHERE sequencenumber IN (:sequenceNumbers)',
            [
                'sequenceNumbers' => array_map(fn (EventEnvelope $eventEnvelope) => $eventEnvelope->sequenceNumber->value, $eventsToReorder)
            ],
            [
                'sequenceNumbers' => ArrayParameterType::STRING
            ]
        );
        $this->connection->commit();

        $mapper = function (EventEnvelope $eventEnvelope): Event {
            $metadata = $event->eventMetadata?->value ?? [];
            $metadata['reorderedByMigration'] = sprintf('Originally recorded at %s with sequence number %d', $eventEnvelope->recordedAt->format(\DateTimeInterface::ATOM), $eventEnvelope->sequenceNumber->value);
            return new Event(
                $eventEnvelope->event->id,
                $eventEnvelope->event->type,
                $eventEnvelope->event->data,
                EventMetadata::fromArray($metadata),
                $eventEnvelope->event->causationId,
                $eventEnvelope->event->correlationId
            );
        };

        // reapply the NodeAggregateWasRemoved events
        $internals->eventStore->commit(
            $streamName,
            Events::fromArray(array_map($mapper, $eventsToReorder)),
            ExpectedVersion::ANY()
        );

        $this->outputLine('Reordered %d removals. Please replay and rebase your other workspaces.', [count($eventsToReorder)]);
    }

    private function backup(ContentRepositoryId $contentRepositoryId): void
    {
        $backupEventTableName = DoctrineEventStoreFactory::databaseTableName($contentRepositoryId)
            . '_bkp_' . date('Y_m_d_H_i_s');
        $this->copyEventTable($backupEventTableName, $contentRepositoryId);
        $this->outputLine(sprintf('Copied events table to %s', $backupEventTableName));
    }

    private function copyEventTable(string $backupEventTableName, ContentRepositoryId $contentRepositoryId): void
    {
        $eventTableName = DoctrineEventStoreFactory::databaseTableName($contentRepositoryId);
        $this->connection->executeStatement(
            'CREATE TABLE ' . $backupEventTableName . ' AS
            SELECT *
            FROM ' . $eventTableName
        );
    }

    private function getInternals(ContentRepositoryId $contentRepositoryId): ContentRepositoryServiceFactoryDependencies
    {
        // NOT API!!!
        $accessor = new class implements ContentRepositoryServiceFactoryInterface {
            public ContentRepositoryServiceFactoryDependencies|null $dependencies;
            public function build(ContentRepositoryServiceFactoryDependencies $serviceFactoryDependencies): ContentRepositoryServiceInterface
            {
                $this->dependencies = $serviceFactoryDependencies;
                return new class implements ContentRepositoryServiceInterface
                {
                };
            }
        };
        $this->contentRepositoryRegistry->buildService($contentRepositoryId, $accessor);
        return $accessor->dependencies;
    }
}
