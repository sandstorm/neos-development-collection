<?php
declare(strict_types=1);

namespace Neos\ContentRepositoryRegistry\Command;

use Neos\ContentRepository\Core\Service\ContentRepositoryMaintainer;
use Neos\ContentRepository\Core\Service\ContentRepositoryMaintainerFactory;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\Subscription\SubscriptionId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Symfony\Component\Console\Output\Output;

/**
 * Manage subscriptions (mainly projections)
 *
 * If any interaction is required "./flow cr:status" can be asked.
 *
 * *Replay*
 *
 * For initialising on a new database - which contains events already - a replay will make sure that the subscriptions
 * are emptied and reapply the events. This can be triggered via "./flow subscription:replay --subscription contentGraph" or "./flow subscription:replayall"
 *
 * And after registering a new subscription a setup as well as a replay of this subscription is also required.
 *
 * *Reactivate*
 *
 * In case a subscription is detached but is reinstalled a reactivation is needed via "./flow subscription:reactivate --subscription contentGraph"
 *
 * Also in case a subscription runs into the error status, its code needs to be fixed, and it can also be attempted to be reactivated.
 *
 * See also {@see ContentRepositoryMaintainer} for more information.
 */
final class SubscriptionCommandController extends CommandController
{
    #[Flow\Inject()]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * Replays the specified subscription of a Content Repository by resetting its state and performing a full catchup.
     *
     * @param string $subscription Identifier of the subscription to replay like it was configured (e.g. "contentGraph", "Vendor.Package:YourProjection")
     * @param string $contentRepository Identifier of the Content Repository instance to operate on
     * @param bool $force Replay the subscription without confirmation. This may take some time!
     * @param bool $quiet If set only fatal errors are rendered to the output (must be used with --force flag to avoid user input)
     */
    public function replayCommand(string $subscription, string $contentRepository = 'default', bool $force = false, bool $quiet = false): void
    {
        if ($quiet) {
            $this->output->getOutput()->setVerbosity(Output::VERBOSITY_QUIET);
        }
        if (!$force && $quiet) {
            $this->outputLine('Cannot run in quiet mode without --force. Please acknowledge that this command will reset and replay this subscription. This may take some time.');
            $this->quit(1);
        }

        if (!$force && !$this->output->askConfirmation(sprintf('> This will replay the subscription "%s" in "%s", which may take some time. Are you sure to proceed? (y/n) ', $subscription, $contentRepository), false)) {
            $this->outputLine('<comment>Abort.</comment>');
            return;
        }

        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $contentRepositoryMaintainer = $this->contentRepositoryRegistry->buildService($contentRepositoryId, new ContentRepositoryMaintainerFactory());

        $progressCallback = null;
        if (!$quiet) {
            $this->outputLine('Replaying events for subscription "%s" of Content Repository "%s" ...', [$subscription, $contentRepositoryId->value]);
            // render memory consumption and time remaining
            $this->output->getProgressBar()->setFormat('debug');
            $this->output->progressStart();
            $progressCallback = fn () => $this->output->progressAdvance();
        }

        $result = $contentRepositoryMaintainer->replaySubscription(SubscriptionId::fromString($subscription), progressCallback: $progressCallback);

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
     * @param bool $force Replay all subscriptions without confirmation. This may take some time!
     * @param bool $quiet If set only fatal errors are rendered to the output (must be used with --force flag to avoid user input)
     */
    public function replayAllCommand(string $contentRepository = 'default', bool $force = false, bool $quiet = false): void
    {
        if ($quiet) {
            $this->output->getOutput()->setVerbosity(Output::VERBOSITY_QUIET);
        }

        if (!$force && $quiet) {
            $this->outputLine('Cannot run in quiet mode without --force. Please acknowledge that this command will reset and replay all subscriptions. This may take some time.');
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

        $result = $contentRepositoryMaintainer->replayAllSubscriptions(progressCallback: $progressCallback);

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
     * Reactivate a subscription
     *
     * The explicit catchup is only needed for projections in the error or detached status with an advanced position.
     * Running a full replay would work but might be overkill, instead this reactivation will just attempt
     * catchup the subscription back to active from its current position.
     *
     * @param string $subscription Identifier of the subscription to reactivate like it was configured (e.g. "contentGraph", "Vendor.Package:YourProjection")
     * @param string $contentRepository Identifier of the Content Repository instance to operate on
     * @param bool $quiet If set only fatal errors are rendered to the output (must be used with --force flag to avoid user input)
     */
    public function reactivateCommand(string $subscription, string $contentRepository = 'default', bool $quiet = false): void
    {
        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $contentRepositoryMaintainer = $this->contentRepositoryRegistry->buildService($contentRepositoryId, new ContentRepositoryMaintainerFactory());

        $progressCallback = null;
        if (!$quiet) {
            $this->outputLine('Reactivate subscription "%s" of Content Repository "%s" ...', [$subscription, $contentRepositoryId->value]);
            // render memory consumption and time remaining
            $this->output->getProgressBar()->setFormat('debug');
            $this->output->progressStart();
            $progressCallback = fn () => $this->output->progressAdvance();
        }

        $result = $contentRepositoryMaintainer->reactivateSubscription(SubscriptionId::fromString($subscription), progressCallback: $progressCallback);

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
