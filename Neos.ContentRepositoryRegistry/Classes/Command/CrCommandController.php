<?php
declare(strict_types=1);

namespace Neos\ContentRepositoryRegistry\Command;

use Neos\ContentRepository\Core\Projection\ProjectionStatusType;
use Neos\ContentRepository\Core\Service\SubscriptionServiceFactory;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\EventStore\StatusType;
use Neos\Flow\Cli\CommandController;
use stdClass;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\Output;

final class CrCommandController extends CommandController
{

    public function __construct(
        private readonly ContentRepositoryRegistry $contentRepositoryRegistry,
    ) {
        parent::__construct();
    }

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
        $subscriptionService = $this->contentRepositoryRegistry->buildService($contentRepositoryId, new SubscriptionServiceFactory());
        $subscriptionService->setupEventStore();
        $setupResult = $subscriptionService->subscriptionEngine->setup();
        if ($setupResult->errors === null) {
            $this->outputLine('<success>Content Repository "%s" was set up</success>', [$contentRepositoryId->value]);
            return;
        }
        $this->outputLine('<success>Setup of Content Repository "%s" produced the following error%s</success>', [$contentRepositoryId->value, $setupResult->errors->count() === 1 ? '' : 's']);
        foreach ($setupResult->errors as $error) {
            $this->outputLine('<error><b>Subscription "%s":</b> %s</error>', [$error->subscriptionId->value, $error->message]);
        }
        $this->quit(1);
    }

    public function subscriptionsBootCommand(string $contentRepository = 'default', bool $quiet = false): void
    {
        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $subscriptionService = $this->contentRepositoryRegistry->buildService($contentRepositoryId, new SubscriptionServiceFactory());
        if (!$quiet) {
            $this->outputLine('Booting new subscriptions');
            // render memory consumption and time remaining
            $this->output->getProgressBar()->setFormat('debug');
            $this->output->progressStart();
            $bootResult = $subscriptionService->subscriptionEngine->boot(progressCallback: fn () => $this->output->progressAdvance());
            $this->output->progressFinish();
            $this->outputLine();
            if ($bootResult->errors === null) {
                $this->outputLine('<success>Done</success>');
                return;
            }
        } else {
            $bootResult = $subscriptionService->subscriptionEngine->boot();
        }
        if ($bootResult->errors !== null) {
            $this->outputLine('<success>Booting of Content Repository "%s" produced the following error%s</success>', [$contentRepositoryId->value, $bootResult->errors->count() === 1 ? '' : 's']);
            foreach ($bootResult->errors as $error) {
                $this->outputLine('<error><b>Subscription "%s":</b> %s</error>', [$error->subscriptionId->value, $error->message]);
            }
            $this->quit(1);
        }
    }

    public function subscriptionsCatchUpCommand(string $contentRepository = 'default', bool $quiet = false): void
    {
        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $subscriptionService = $this->contentRepositoryRegistry->buildService($contentRepositoryId, new SubscriptionServiceFactory());
        $subscriptionService->subscriptionEngine->catchUpActive();
    }

    public function subscriptionsResetCommand(string $contentRepository = 'default', bool $force = false): void
    {
        if (!$force && !$this->output->askConfirmation('<error>Are you sure? (y/n)</error> ', false)) {
            $this->outputLine('Cancelled');
            $this->quit();
        }
        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $subscriptionService = $this->contentRepositoryRegistry->buildService($contentRepositoryId, new SubscriptionServiceFactory());
        $resetResult = $subscriptionService->subscriptionEngine->reset();
        if ($resetResult->errors === null) {
            $this->outputLine('<success>Content Repository "%s" was reset</success>', [$contentRepositoryId->value]);
            return;
        }
        $this->outputLine('<success>Reset of Content Repository "%s" produced the following error%s</success>', [$contentRepositoryId->value, $resetResult->errors->count() === 1 ? '' : 's']);
        foreach ($resetResult->errors as $error) {
            $this->outputLine('<error><b>Subscription "%s":</b> %s</error>', [$error->subscriptionId->value, $error->message]);
        }
        $this->quit(1);
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
        $subscriptionService = $this->contentRepositoryRegistry->buildService($contentRepositoryId, new SubscriptionServiceFactory());
        $eventStoreStatus = $subscriptionService->eventStoreStatus();
        $hasErrors = false;
        $setupRequired = false;
        $bootingRequired = false;
        $resetRequired = false;

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
        $subscriptionStatuses = $subscriptionService->subscriptionEngine->subscriptionStatuses();
        if ($subscriptionStatuses->isEmpty()) {
            $this->outputLine('<error>There are no registered subscriptions yet, please run <em>./flow cr:setup</em></error>');
            $this->quit(1);
        }
        foreach ($subscriptionStatuses as $status) {
            $this->outputLine('  <b>%s</b>:', [$status->subscriptionId->value]);
            $this->output('    Subscription: ', [$status->subscriptionId->value]);
            $this->output(match ($status->subscriptionStatus) {
                SubscriptionStatus::NEW => '<comment>NEW</comment>',
                SubscriptionStatus::BOOTING => '<comment>BOOTING</comment>',
                SubscriptionStatus::ACTIVE => '<success>ACTIVE</success>',
                SubscriptionStatus::PAUSED => '<comment>PAUSED</comment>',
                SubscriptionStatus::FINISHED => '<comment>FINISHED</comment>',
                SubscriptionStatus::DETACHED => '<comment>DETACHED</comment>',
                SubscriptionStatus::ERROR => '<error>ERROR</error>',
            });
            $this->outputLine(' at position <b>%d</b>', [$status->subscriptionPosition->value]);
            $hasErrors |= $status->subscriptionStatus === SubscriptionStatus::ERROR;
            $bootingRequired |= $status->subscriptionStatus === SubscriptionStatus::BOOTING;
            if ($verbose && $status->subscriptionError !== null) {
                $lines = explode(chr(10), $status->subscriptionError->errorMessage ?: '<comment>No details available.</comment>');
                foreach ($lines as $line) {
                    $this->outputLine('<error>      %s</error>', [$line]);
                }
            }
            if ($status->projectionStatus !== null) {
                $this->output('    Projection: ');
                $this->outputLine(match ($status->projectionStatus->type) {
                    ProjectionStatusType::OK => '<success>OK</success>',
                    ProjectionStatusType::SETUP_REQUIRED => '<comment>Setup required!</comment>',
                    ProjectionStatusType::REPLAY_REQUIRED => '<comment>Replay required!</comment>',
                    ProjectionStatusType::ERROR => '<error>ERROR</error>',
                });
                $hasErrors |= $status->projectionStatus->type === ProjectionStatusType::ERROR;
                $setupRequired |= $status->projectionStatus->type === ProjectionStatusType::SETUP_REQUIRED;
                $resetRequired |= $status->projectionStatus->type === ProjectionStatusType::REPLAY_REQUIRED;
                if ($verbose && ($status->projectionStatus->type !== ProjectionStatusType::OK || $status->projectionStatus->details)) {
                    $lines = explode(chr(10), $status->projectionStatus->details ?: '<comment>No details available.</comment>');
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
                $this->outputLine('<comment>Some subscriptions need to be booted, please run <em>./flow cr:subscriptionsboot</em></comment>');
            }
            if ($resetRequired) {
                $this->outputLine('<comment>Some subscriptions need to be replayed, please run <em>./flow cr:subscriptionsreset</em></comment>');
            }
            if ($hasErrors) {
                $this->outputLine('<error>Some subscriptions/projections have failed</error>');
            }
        }
        if ($hasErrors) {
            $this->quit(1);
        }
    }
}
