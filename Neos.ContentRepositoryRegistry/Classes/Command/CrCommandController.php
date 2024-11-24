<?php
declare(strict_types=1);

namespace Neos\ContentRepositoryRegistry\Command;

use Neos\ContentRepository\Core\Projection\ProjectionSetupStatusType;
use Neos\ContentRepository\Core\Service\ContentRepositoryMaintainerFactory;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\Subscription\DetachedSubscriptionStatus;
use Neos\ContentRepository\Core\Subscription\ProjectionSubscriptionStatus;
use Neos\ContentRepository\Core\Subscription\SubscriptionId;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\EventStore\Model\EventStore\StatusType;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Symfony\Component\Console\Output\Output;

final class CrCommandController extends CommandController
{
    #[Flow\Inject()]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * Sets up and checks required dependencies for a Content Repository instance
     * Like event store and projection database tables.
     *
     * Note: This command is non-destructive, i.e. it can be executed without side effects even if all dependencies are up-to-date
     * Therefore it makes sense to include this command into the Continuous Integration
     *
     * To check if the content repository needs to be setup look into cr:status.
     * That command will also display information what is about to be migrated.
     *
     * @param string $contentRepository Identifier of the Content Repository to set up
     */
    public function setupCommand(string $contentRepository = 'default'): void
    {
        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $contentRepositoryMaintainer = $this->contentRepositoryRegistry->buildService($contentRepositoryId, new ContentRepositoryMaintainerFactory());

        $result = $contentRepositoryMaintainer->setUp();
        if ($result !== null) {
            $this->outputLine('<error>%s</error>', [$result->getMessage()]);
            $this->quit(1);
        }
        $this->outputLine('<success>Content Repository "%s" was set up</success>', [$contentRepositoryId->value]);
    }

    /**
     * Determine and output the status of the event store and all registered projections for a given Content Repository
     *
     * In verbose mode it will also display information what should and will be migrated when cr:setup is used.
     *
     * @param string $contentRepository Identifier of the Content Repository to determine the status for
     * @param bool $verbose If set, more details will be shown
     * @param bool $quiet If set, no output is generated. This is useful if only the exit code (0 = all OK, 1 = errors or warnings) is of interest
     */
    public function statusCommand(string $contentRepository = 'default', bool $verbose = false, bool $quiet = false): void
    {
        if ($quiet) {
            $this->output->getOutput()->setVerbosity(Output::VERBOSITY_QUIET);
        }
        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $contentRepositoryMaintainer = $this->contentRepositoryRegistry->buildService($contentRepositoryId, new ContentRepositoryMaintainerFactory());
        $eventStoreStatus = $contentRepositoryMaintainer->eventStoreStatus();
        $hasErrors = false;
        $setupRequired = false;
        $bootingRequired = false;
        $this->output('Event Store: ');
        $this->outputLine(match ($eventStoreStatus->type) {
            StatusType::OK => '<success>OK</success>',
            StatusType::SETUP_REQUIRED => '<comment>Setup required!</comment>',
            StatusType::ERROR => '<error>ERROR</error>',
        });
        $hasErrors |= $eventStoreStatus->type === StatusType::ERROR;
        if ($verbose && $eventStoreStatus->details !== '') {
            $this->outputFormatted($eventStoreStatus->details, [], 2);
        }
        $this->outputLine();
        $this->outputLine('Subscriptions:');
        $subscriptionStatuses = $contentRepositoryMaintainer->subscriptionStatuses();
        if ($subscriptionStatuses->isEmpty()) {
            $this->outputLine('<error>There are no registered subscriptions yet, please run <em>./flow cr:setup</em></error>');
            $this->quit(1);
        }
        foreach ($subscriptionStatuses as $status) {
            if ($status instanceof DetachedSubscriptionStatus) {
                $this->outputLine('  <b>%s</b>:', [$status->subscriptionId->value]);
                $this->output('    Subscription: ');
                $this->output('%s <comment>DETACHED</comment>', [$status->subscriptionId->value, $status->subscriptionStatus === SubscriptionStatus::DETACHED ? 'is' : 'will be']);
                $this->outputLine(' at position <b>%d</b>', [$status->subscriptionPosition->value]);
            }
            if ($status instanceof ProjectionSubscriptionStatus) {
                $this->outputLine('  <b>%s</b>:', [$status->subscriptionId->value]);
                $this->output('    Projection: ');
                $this->output(match ($status->subscriptionStatus) {
                    SubscriptionStatus::NEW => '<comment>NEW</comment>',
                    SubscriptionStatus::BOOTING => '<comment>BOOTING</comment>',
                    SubscriptionStatus::ACTIVE => '<success>ACTIVE</success>',
                    SubscriptionStatus::DETACHED => '<comment>DETACHED</comment>',
                    SubscriptionStatus::ERROR => '<error>ERROR</error>',
                });
                $this->outputLine(' at position <b>%d</b>', [$status->subscriptionPosition->value]);
                $hasErrors |= $status->subscriptionStatus === SubscriptionStatus::ERROR;
                $bootingRequired |= $status->subscriptionStatus === SubscriptionStatus::BOOTING;
                // detached can be reattached via setup:
                $setupRequired |= $status->subscriptionStatus === SubscriptionStatus::DETACHED;
                if ($verbose && $status->subscriptionError !== null) {
                    $lines = explode(chr(10), $status->subscriptionError->errorMessage ?: '<comment>No details available.</comment>');
                    foreach ($lines as $line) {
                        $this->outputLine('<error>      %s</error>', [$line]);
                    }
                }
                $this->output('    Setup: ');
                $this->outputLine(match ($status->setupStatus->type) {
                    ProjectionSetupStatusType::OK => '<success>OK</success>',
                    ProjectionSetupStatusType::SETUP_REQUIRED => '<comment>SETUP REQUIRED</comment>',
                    ProjectionSetupStatusType::ERROR => '<error>ERROR</error>',
                });
                $hasErrors |= $status->setupStatus->type === ProjectionSetupStatusType::ERROR;
                $setupRequired |= $status->setupStatus->type === ProjectionSetupStatusType::SETUP_REQUIRED;
                if ($verbose && ($status->setupStatus->type !== ProjectionSetupStatusType::OK || $status->setupStatus->details)) {
                    $lines = explode(chr(10), $status->setupStatus->details ?: '<comment>No details available.</comment>');
                    foreach ($lines as $line) {
                        $this->outputLine('      ' . $line);
                    }
                    $this->outputLine();
                }
            }
        }
        if ($verbose) {
            $this->outputLine();
            if ($setupRequired) {
                $this->outputLine('<comment>Setup required, please run <em>./flow cr:setup</em></comment>');
            }
            if ($bootingRequired) {
                $this->outputLine('<comment>Catchup needed for projections, please run <em>./flow cr:projectioncatchup [projection-name]</em></comment>');
            }
            if ($hasErrors) {
                $this->outputLine('<error>Some projections are not okay</error>');
            }
        }
        if ($hasErrors) {
            $this->quit(1);
        }
    }

    /**
     * Replays the specified projection of a Content Repository by resetting its state and performing a full catchup.
     *
     * @param string $projection Identifier of the projection to replay like it was configured (e.g. "contentGraph", "Vendor.Package:YourProjection")
     * @param string $contentRepository Identifier of the Content Repository instance to operate on
     * @param bool $force Replay the projection without confirmation. This may take some time!
     * @param bool $quiet If set only fatal errors are rendered to the output (must be used with --force flag to avoid user input)
     */
    public function projectionReplayCommand(string $projection, string $contentRepository = 'default', bool $force = false, bool $quiet = false): void
    {
        if ($quiet) {
            $this->output->getOutput()->setVerbosity(Output::VERBOSITY_QUIET);
        }
        if (!$force && $quiet) {
            $this->outputLine('Cannot run in quiet mode without --force. Please acknowledge that this command will reset and replay this projection. This may take some time.');
            $this->quit(1);
        }

        if (!$force && !$this->output->askConfirmation(sprintf('> This will replay the projection "%s" in "%s", which may take some time. Are you sure to proceed? (y/n) ', $projection, $contentRepository), false)) {
            $this->outputLine('<comment>Abort.</comment>');
            return;
        }

        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $contentRepositoryMaintainer = $this->contentRepositoryRegistry->buildService($contentRepositoryId, new ContentRepositoryMaintainerFactory());

        $progressCallback = null;
        if (!$quiet) {
            $this->outputLine('Replaying events for projection "%s" of Content Repository "%s" ...', [$projection, $contentRepositoryId->value]);
            // render memory consumption and time remaining
            $this->output->getProgressBar()->setFormat('debug');
            $this->output->progressStart();
            $progressCallback = fn () => $this->output->progressAdvance();
        }

        $result = $contentRepositoryMaintainer->replayProjection(SubscriptionId::fromString($projection), progressCallback: $progressCallback);

        if (!$quiet) {
            $this->output->progressFinish();
            $this->outputLine();
        }

        if ($result !== null) {
            $this->outputLine('<error>%s</error>', [$result->getMessage()]);
            $this->quit(1);
        } elseif (!$quiet) {
            $this->outputLine('<success>Done.</success>');
        }
    }

    /**
     * Replays all projections of the specified Content Repository by resetting their states and performing a full catchup
     *
     * @param string $contentRepository Identifier of the Content Repository instance to operate on
     * @param bool $force Replay the projection without confirmation. This may take some time!
     * @param bool $quiet If set only fatal errors are rendered to the output (must be used with --force flag to avoid user input)
     */
    public function projectionReplayAllCommand(string $contentRepository = 'default', bool $force = false, bool $quiet = false): void
    {
        if ($quiet) {
            $this->output->getOutput()->setVerbosity(Output::VERBOSITY_QUIET);
        }

        if (!$force && $quiet) {
            $this->outputLine('Cannot run in quiet mode without --force. Please acknowledge that this command will reset and replay this projection. This may take some time.');
            $this->quit(1);
        }

        if (!$force && !$this->output->askConfirmation(sprintf('> This will replay all projections in "%s", which may take some time. Are you sure to proceed? (y/n) ', $contentRepository), false)) {
            $this->outputLine('<comment>Abort.</comment>');
            return;
        }

        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $contentRepositoryMaintainer = $this->contentRepositoryRegistry->buildService($contentRepositoryId, new ContentRepositoryMaintainerFactory());

        $progressCallback = null;
        if (!$quiet) {
            $this->outputLine('Replaying events for all projections of Content Repository "%s" ...', [$contentRepositoryId->value]);
            // render memory consumption and time remaining
            // todo maybe reintroduce pretty output: https://github.com/neos/neos-development-collection/pull/5010 but without using highestSequenceNumber
            $this->output->getProgressBar()->setFormat('debug');
            $this->output->progressStart();
            $progressCallback = fn () => $this->output->progressAdvance();
        }

        $result = $contentRepositoryMaintainer->replayAllProjections(progressCallback: $progressCallback);

        if (!$quiet) {
            $this->output->progressFinish();
            $this->outputLine();
        }

        if ($result !== null) {
            $this->outputLine('<error>%s</error>', [$result->getMessage()]);
            $this->quit(1);
        } elseif (!$quiet) {
            $this->outputLine('<success>Done.</success>');
        }
    }

    /**
     * Catchup one specific projection.
     *
     * The explicit catchup is required for new projections in the booting state, after installing a new projection or fixing its errors.
     *
     * @param string $projection Identifier of the projection to catchup like it was configured (e.g. "contentGraph", "Vendor.Package:YourProjection")
     * @param string $contentRepository Identifier of the Content Repository instance to operate on
     * @param bool $quiet If set only fatal errors are rendered to the output (must be used with --force flag to avoid user input)
     */
    public function projectionCatchupCommand(string $projection, string $contentRepository = 'default', bool $quiet = false): void
    {
        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $contentRepositoryMaintainer = $this->contentRepositoryRegistry->buildService($contentRepositoryId, new ContentRepositoryMaintainerFactory());

        $progressCallback = null;
        if (!$quiet) {
            $this->outputLine('Catchup projection "%s" of Content Repository "%s" ...', [$projection, $contentRepositoryId->value]);
            // render memory consumption and time remaining
            $this->output->getProgressBar()->setFormat('debug');
            $this->output->progressStart();
            $progressCallback = fn () => $this->output->progressAdvance();
        }

        $result = $contentRepositoryMaintainer->catchupProjection(SubscriptionId::fromString($projection), progressCallback: $progressCallback);

        if (!$quiet) {
            $this->output->progressFinish();
            $this->outputLine();
        }

        if ($result !== null) {
            $this->outputLine('<error>%s</error>', [$result->getMessage()]);
            $this->quit(1);
        } elseif (!$quiet) {
            $this->outputLine('<success>Done.</success>');
        }
    }
}
