<?php

/*
 * This file is part of the Neos.ContentRepository package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\ContentRepository\Core;

use Neos\ContentRepository\Core\CommandHandler\CommandBus;
use Neos\ContentRepository\Core\CommandHandler\CommandHookInterface;
use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\Dimension\ContentDimensionSourceInterface;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\InterDimensionalVariationGraph;
use Neos\ContentRepository\Core\EventStore\EventNormalizer;
use Neos\ContentRepository\Core\EventStore\EventPersister;
use Neos\ContentRepository\Core\EventStore\EventsToPublish;
use Neos\ContentRepository\Core\EventStore\InitiatingEventMetadata;
use Neos\ContentRepository\Core\Feature\Security\AuthProviderInterface;
use Neos\ContentRepository\Core\Feature\Security\Dto\UserId;
use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\CatchUp;
use Neos\ContentRepository\Core\Projection\CatchUpHookFactoryDependencies;
use Neos\ContentRepository\Core\Projection\CatchUpOptions;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphProjectionInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphReadModelInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ProjectionInterface;
use Neos\ContentRepository\Core\Projection\ProjectionsAndCatchUpHooks;
use Neos\ContentRepository\Core\Projection\ProjectionStateInterface;
use Neos\ContentRepository\Core\Projection\ProjectionStatuses;
use Neos\ContentRepository\Core\Projection\WithMarkStaleInterface;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryStatus;
use Neos\ContentRepository\Core\SharedModel\Exception\WorkspaceDoesNotExist;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStream;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreams;
use Neos\ContentRepository\Core\SharedModel\Workspace\Workspace;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Core\SharedModel\Workspace\Workspaces;
use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Exception\ConcurrencyException;
use Neos\EventStore\Model\EventEnvelope;
use Neos\EventStore\Model\EventStream\VirtualStreamName;
use Psr\Clock\ClockInterface;

/**
 * Main Entry Point to the system. Encapsulates the full event-sourced Content Repository.
 *
 * Use this to:
 * - set up the necessary database tables and contents via {@see ContentRepository::setUp()}
 * - send commands to the system (to mutate state) via {@see ContentRepository::handle()}
 * - access projection state (to read state) via {@see ContentRepository::projectionState()}
 * - catch up projections via {@see ContentRepository::catchUpProjection()}
 *
 * @api
 */
final class ContentRepository
{
    /**
     * @var array<class-string<ProjectionStateInterface>, ProjectionStateInterface>
     */
    private array $projectionStateCache;

    /**
     * @internal use the {@see ContentRepositoryFactory::getOrBuild()} to instantiate
     */
    public function __construct(
        public readonly ContentRepositoryId $id,
        private readonly CommandBus $commandBus,
        private readonly EventStoreInterface $eventStore,
        private readonly ProjectionsAndCatchUpHooks $projectionsAndCatchUpHooks,
        private readonly EventNormalizer $eventNormalizer,
        private readonly EventPersister $eventPersister,
        private readonly NodeTypeManager $nodeTypeManager,
        private readonly InterDimensionalVariationGraph $variationGraph,
        private readonly ContentDimensionSourceInterface $contentDimensionSource,
        private readonly AuthProviderInterface $authProvider,
        private readonly ClockInterface $clock,
        private readonly ContentGraphReadModelInterface $contentGraphReadModel,
        private readonly CommandHookInterface $commandHook,
    ) {
    }

    /**
     * The only API to send commands (mutation intentions) to the system.
     *
     * @param CommandInterface $command
     * @throws AccessDenied
     */
    public function handle(CommandInterface $command): void
    {
        $command = $this->commandHook->onBeforeHandle($command);
        $privilege = $this->authProvider->canExecuteCommand($command);
        if (!$privilege->granted) {
            throw AccessDenied::becauseCommandIsNotGranted($command, $privilege->getReason());
        }

        $toPublish = $this->commandBus->handle($command);

        // simple case
        if ($toPublish instanceof EventsToPublish) {
            $eventsToPublish = $this->enrichEventsToPublishWithMetadata($toPublish);
            $this->eventPersister->publishWithoutCatchup($eventsToPublish);
            $this->catchupProjections();
            return;
        }

        // control-flow aware command handling via generator
        try {
            foreach ($toPublish as $yieldedEventsToPublish) {
                $eventsToPublish = $this->enrichEventsToPublishWithMetadata($yieldedEventsToPublish);
                try {
                    $this->eventPersister->publishWithoutCatchup($eventsToPublish);
                } catch (ConcurrencyException $concurrencyException) {
                    // we pass the exception into the generator (->throw), so it could be try-caught and reacted upon:
                    //
                    //   try {
                    //      yield EventsToPublish(...);
                    //   } catch (ConcurrencyException $e) {
                    //      yield $this->reopenContentStream();
                    //      throw $e;
                    //   }
                    $yieldedErrorStrategy = $toPublish->throw($concurrencyException);
                    if ($yieldedErrorStrategy instanceof EventsToPublish) {
                        $this->eventPersister->publishWithoutCatchup($yieldedErrorStrategy);
                    }
                    throw $concurrencyException;
                }
            }
        } finally {
            // We always NEED to catchup even if there was an unexpected ConcurrencyException to make sure previous commits are handled.
            // Technically it would be acceptable for the catchup to fail here (due to hook errors) because all the events are already persisted.
            $this->catchupProjections();
        }
    }


    /**
     * @template T of ProjectionStateInterface
     * @param class-string<T> $projectionStateClassName
     * @return T
     */
    public function projectionState(string $projectionStateClassName): ProjectionStateInterface
    {
        if (!isset($this->projectionStateCache)) {
            foreach ($this->projectionsAndCatchUpHooks->projections as $projection) {
                if ($projection instanceof ContentGraphProjectionInterface) {
                    continue;
                }
                $projectionState = $projection->getState();
                $this->projectionStateCache[$projectionState::class] = $projectionState;
            }
        }
        if (isset($this->projectionStateCache[$projectionStateClassName])) {
            /** @var T $projectionState */
            $projectionState = $this->projectionStateCache[$projectionStateClassName];
            return $projectionState;
        }
        if (in_array(ContentGraphReadModelInterface::class, class_implements($projectionStateClassName), true)) {
            throw new \InvalidArgumentException(sprintf('Accessing the internal content repository projection state via %s(%s) is not allowed. Please use the API on the content repository instead.', __FUNCTION__, $projectionStateClassName), 1729338679);
        }

        throw new \InvalidArgumentException(sprintf('A projection state of type "%s" is not registered in this content repository instance.', $projectionStateClassName), 1662033650);
    }

    /**
     * @param class-string<ProjectionInterface<ProjectionStateInterface>> $projectionClassName
     */
    public function catchUpProjection(string $projectionClassName, CatchUpOptions $options): void
    {
        $projection = $this->projectionsAndCatchUpHooks->projections->get($projectionClassName);

        $catchUpHookFactory = $this->projectionsAndCatchUpHooks->getCatchUpHookFactoryForProjection($projection);
        $catchUpHook = $catchUpHookFactory?->build(CatchUpHookFactoryDependencies::create(
            $this->id,
            $projection->getState(),
            $this->nodeTypeManager,
            $this->contentDimensionSource,
            $this->variationGraph
        ));

        // TODO allow custom stream name per projection
        $streamName = VirtualStreamName::all();
        $eventStream = $this->eventStore->load($streamName);
        if ($options->maximumSequenceNumber !== null) {
            $eventStream = $eventStream->withMaximumSequenceNumber($options->maximumSequenceNumber);
        }

        $eventApplier = function (EventEnvelope $eventEnvelope) use ($projection, $catchUpHook, $options) {
            $event = $this->eventNormalizer->denormalize($eventEnvelope->event);
            if ($options->progressCallback !== null) {
                ($options->progressCallback)($event, $eventEnvelope);
            }
            if (!$projection->canHandle($event)) {
                return;
            }
            $catchUpHook?->onBeforeEvent($event, $eventEnvelope);
            $projection->apply($event, $eventEnvelope);
            if ($projection instanceof WithMarkStaleInterface) {
                $projection->markStale();
            }
            $catchUpHook?->onAfterEvent($event, $eventEnvelope);
        };

        $catchUp = CatchUp::create($eventApplier, $projection->getCheckpointStorage());

        if ($catchUpHook !== null) {
            $catchUpHook->onBeforeCatchUp();
            $catchUp = $catchUp->withOnBeforeBatchCompleted(fn() => $catchUpHook->onBeforeBatchCompleted());
        }
        $catchUp->run($eventStream);
        $catchUpHook?->onAfterCatchUp();
    }

    public function catchupProjections(): void
    {
        foreach ($this->projectionsAndCatchUpHooks->projections as $projection) {
            // FIXME optimise by only loading required events once and not per projection
            // see https://github.com/neos/neos-development-collection/pull/4988/
            $this->catchUpProjection($projection::class, CatchUpOptions::create());
        }
    }

    public function setUp(): void
    {
        $this->eventStore->setup();
        foreach ($this->projectionsAndCatchUpHooks->projections as $projection) {
            $projection->setUp();
        }
    }

    public function status(): ContentRepositoryStatus
    {
        $projectionStatuses = ProjectionStatuses::createEmpty();
        foreach ($this->projectionsAndCatchUpHooks->projections as $projectionClassName => $projection) {
            $projectionStatuses = $projectionStatuses->with($projectionClassName, $projection->status());
        }
        return new ContentRepositoryStatus(
            $this->eventStore->status(),
            $projectionStatuses,
        );
    }

    public function resetProjectionStates(): void
    {
        foreach ($this->projectionsAndCatchUpHooks->projections as $projection) {
            $projection->reset();
        }
    }

    /**
     * @param class-string<ProjectionInterface<ProjectionStateInterface>> $projectionClassName
     */
    public function resetProjectionState(string $projectionClassName): void
    {
        $projection = $this->projectionsAndCatchUpHooks->projections->get($projectionClassName);
        $projection->reset();
    }

    /**
     * @throws WorkspaceDoesNotExist if the workspace does not exist
     * @throws AccessDenied if no read access is granted to the workspace ({@see AuthProviderInterface})
     */
    public function getContentGraph(WorkspaceName $workspaceName): ContentGraphInterface
    {
        $privilege = $this->authProvider->canReadNodesFromWorkspace($workspaceName);
        if (!$privilege->granted) {
            throw AccessDenied::becauseWorkspaceCantBeRead($workspaceName, $privilege->getReason());
        }
        return $this->contentGraphReadModel->getContentGraph($workspaceName);
    }

    /**
     * Main API to retrieve a content subgraph, taking VisibilityConstraints of the current user
     * into account ({@see AuthProviderInterface::getVisibilityConstraints()})
     *
     * @throws WorkspaceDoesNotExist if the workspace does not exist
     * @throws AccessDenied if no read access is granted to the workspace ({@see AuthProviderInterface})
     */
    public function getContentSubgraph(WorkspaceName $workspaceName, DimensionSpacePoint $dimensionSpacePoint): ContentSubgraphInterface
    {
        $contentGraph = $this->getContentGraph($workspaceName);
        $visibilityConstraints = $this->authProvider->getVisibilityConstraints($workspaceName);
        return $contentGraph->getSubgraph($dimensionSpacePoint, $visibilityConstraints);
    }

    /**
     * Returns the workspace with the given name, or NULL if it does not exist in this content repository
     */
    public function findWorkspaceByName(WorkspaceName $workspaceName): ?Workspace
    {
        return $this->contentGraphReadModel->findWorkspaceByName($workspaceName);
    }

    /**
     * Returns all workspaces of this content repository. To limit the set, {@see Workspaces::find()} and {@see Workspaces::filter()} can be used
     * as well as {@see Workspaces::getBaseWorkspaces()} and {@see Workspaces::getDependantWorkspaces()}.
     */
    public function findWorkspaces(): Workspaces
    {
        return $this->contentGraphReadModel->findWorkspaces();
    }

    public function findContentStreamById(ContentStreamId $contentStreamId): ?ContentStream
    {
        return $this->contentGraphReadModel->findContentStreamById($contentStreamId);
    }

    public function findContentStreams(): ContentStreams
    {
        return $this->contentGraphReadModel->findContentStreams();
    }

    public function getNodeTypeManager(): NodeTypeManager
    {
        return $this->nodeTypeManager;
    }

    public function getVariationGraph(): InterDimensionalVariationGraph
    {
        return $this->variationGraph;
    }

    public function getContentDimensionSource(): ContentDimensionSourceInterface
    {
        return $this->contentDimensionSource;
    }

    private function enrichEventsToPublishWithMetadata(EventsToPublish $eventsToPublish): EventsToPublish
    {
        $initiatingUserId = $this->authProvider->getAuthenticatedUserId() ?? UserId::forSystemUser();
        $initiatingTimestamp = $this->clock->now();

        return new EventsToPublish(
            $eventsToPublish->streamName,
            InitiatingEventMetadata::enrichEventsWithInitiatingMetadata(
                $eventsToPublish->events,
                $initiatingUserId,
                $initiatingTimestamp
            ),
            $eventsToPublish->expectedVersion,
        );
    }
}
