<?php
declare(strict_types=1);

namespace Neos\ContentRepositoryRegistry\Command;

use Neos\ContentRepository\Core\Projection\ProjectionStatusType;
use Neos\ContentRepository\Core\Service\SubscriptionServiceFactory;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
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
        $status = new stdClass();//TODO $this->contentRepositoryRegistry->get($contentRepositoryId)->status();

        $this->output('Event Store: ');
        $this->outputLine(match ($status->eventStoreStatus->type) {
            StatusType::OK => '<success>OK</success>',
            StatusType::SETUP_REQUIRED => '<comment>Setup required!</comment>',
            StatusType::ERROR => '<error>ERROR</error>',
        });
        if ($verbose && $status->eventStoreStatus->details !== '') {
            $this->outputFormatted($status->eventStoreStatus->details, [], 2);
        }
        $this->outputLine();
        foreach ($status->projectionStatuses as $projectionName => $projectionStatus) {
            $this->output('Projection "<b>%s</b>": ', [$projectionName]);
            $this->outputLine(match ($projectionStatus->type) {
                ProjectionStatusType::OK => '<success>OK</success>',
                ProjectionStatusType::SETUP_REQUIRED => '<comment>Setup required!</comment>',
                ProjectionStatusType::REPLAY_REQUIRED => '<comment>Replay required!</comment>',
                ProjectionStatusType::ERROR => '<error>ERROR</error>',
            });
            if ($verbose && ($projectionStatus->type !== ProjectionStatusType::OK || $projectionStatus->details)) {
                $lines = explode(chr(10), $projectionStatus->details ?: '<comment>No details available.</comment>');
                foreach ($lines as $line) {
                    $this->outputLine('  ' . $line);
                }
                $this->outputLine();
            }
        }
        if (!$status->isOk()) {
            $this->quit(1);
        }
    }
}
