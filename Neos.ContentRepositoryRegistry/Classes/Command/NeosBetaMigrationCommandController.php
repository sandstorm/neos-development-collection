<?php

declare(strict_types=1);

namespace Neos\ContentRepositoryRegistry\Command;

use Doctrine\DBAL\Connection;
use Neos\ContentGraph\DoctrineDbalAdapter\DoctrineDbalContentGraphProjection;
use Neos\ContentRepository\Core\Projection\CatchUpOptions;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\ContentRepositoryRegistry\Factory\EventStore\DoctrineEventStoreFactory;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Symfony\Component\Console\Helper\ProgressBar;

class NeosBetaMigrationCommandController extends CommandController
{
    #[Flow\Inject()]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject()]
    protected Connection $connection;

    public function fixReplayCommand(string $contentRepository = 'default', bool $resetProjection = true): void
    {
        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);

        $this->backup($contentRepositoryId);


        $progressBar = new ProgressBar($this->output->getOutput());

        $progressBar->start($this->highestSequenceNumber($contentRepositoryId)->value, 1);
        $options = CatchUpOptions::create(progressCallback: fn () => $progressBar->advance());

        if ($resetProjection) {
            $contentRepository->resetProjectionState(DoctrineDbalContentGraphProjection::class);
        }

        $automaticRemovedSequenceNumbers = [];
        $manualRemovedSequenceNumbers = [];

        do {
            try {
                $contentRepository->catchUpProjection(DoctrineDbalContentGraphProjection::class, $options);
            } catch (\Throwable $e) {
                $this->outputLine();

                if (preg_match('/^Exception while catching up to sequence number (\d+)/', $e->getMessage(), $matches) !== 1) {
                    $this->outputLine('<error>Could not replay because of unexpected error</error>');
                    $this->outputLine('Removed %d other events: %s', [count($automaticRemovedSequenceNumbers), join(', ', $automaticRemovedSequenceNumbers)]);
                    throw $e;
                }
                $failedSequenceNumber = SequenceNumber::fromInteger((int)$matches[1]);

                $eventRow = $this->getEventEnvelopeData($failedSequenceNumber, $contentRepositoryId);

                if ($eventRow['metadata'] !== null) {
                    $this->outputLine('<error>Did not delete event %s because it doesnt seem to be auto generated</error>', [$failedSequenceNumber->value]);
                    $this->outputLine('The exception: <error>%s</error>', [$e->getMessage()]);
                    $this->outputLine(json_encode($eventRow, JSON_PRETTY_PRINT));
                    $this->outputLine();
                    if ($this->output->askConfirmation(sprintf('> still delete it %d? (y/n) ', $failedSequenceNumber->value), false)) {
                        $manualRemovedSequenceNumbers[] = $failedSequenceNumber->value;
                        $this->deleteEvent($failedSequenceNumber, $contentRepositoryId);
                        continue;
                    }
                    $this->outputLine('Removed %d other events: %s', [count($automaticRemovedSequenceNumbers), join(', ', $automaticRemovedSequenceNumbers)]);
                    throw $e;
                }

                $this->outputLine('<comment>Deleted event %s because it seems to be invalid and auto generated</comment>', [$failedSequenceNumber->value]);
                $this->outputLine(json_encode($eventRow));

                $automaticRemovedSequenceNumbers[] = $failedSequenceNumber->value;
                $this->deleteEvent($failedSequenceNumber, $contentRepositoryId);

                $this->outputLine();
                continue;
            }

            $progressBar->finish();

            $this->outputLine();
            $this->outputLine('Replay was successfully.');
            $this->outputLine('Removed %d automatic events: %s', [count($automaticRemovedSequenceNumbers), join(', ', $automaticRemovedSequenceNumbers)]);
            if ($manualRemovedSequenceNumbers) {
                $this->outputLine('Also removed %d events manually: %s', [count($manualRemovedSequenceNumbers), join(', ', $manualRemovedSequenceNumbers)]);
            }

            return;

        } while (true);
    }

    public function highestSequenceNumber(ContentRepositoryId $contentRepositoryId): SequenceNumber
    {
        $eventTableName = DoctrineEventStoreFactory::databaseTableName($contentRepositoryId);
        return SequenceNumber::fromInteger((int)$this->connection->fetchOne(
            'SELECT sequencenumber FROM ' . $eventTableName . ' ORDER BY sequencenumber ASC'
        ));
    }


    private function backup(ContentRepositoryId $contentRepositoryId): void
    {
        $backupEventTableName = DoctrineEventStoreFactory::databaseTableName($contentRepositoryId)
            . '_bkp_' . date('Y_m_d_H_i_s');
        $this->copyEventTable($backupEventTableName, $contentRepositoryId);
        $this->outputLine(sprintf('Copied events table to %s', $backupEventTableName));
    }

    /**
     * @return array<mixed>
     */
    private function getEventEnvelopeData(SequenceNumber $sequenceNumber, ContentRepositoryId $contentRepositoryId): array
    {
        $eventTableName = DoctrineEventStoreFactory::databaseTableName($contentRepositoryId);
        return $this->connection->fetchAssociative(
            'SELECT * FROM ' . $eventTableName . ' WHERE sequencenumber=:sequenceNumber',
            [
                'sequenceNumber' => $sequenceNumber->value,
            ]
        );
    }

    private function deleteEvent(SequenceNumber $sequenceNumber, ContentRepositoryId $contentRepositoryId): void
    {
        $eventTableName = DoctrineEventStoreFactory::databaseTableName($contentRepositoryId);
        $this->connection->beginTransaction();
        $this->connection->executeStatement(
            'DELETE FROM ' . $eventTableName . ' WHERE sequencenumber=:sequenceNumber',
            [
                'sequenceNumber' => $sequenceNumber->value
            ]
        );
        $this->connection->commit();
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
}
