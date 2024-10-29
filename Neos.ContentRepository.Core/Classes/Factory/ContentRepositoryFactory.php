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

namespace Neos\ContentRepository\Core\Factory;

use Neos\ContentRepository\Core\CommandHandler\CommandBus;
use Neos\ContentRepository\Core\CommandHandler\CommandHandlingDependencies;
use Neos\ContentRepository\Core\CommandHandler\CommandSimulatorFactory;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Dimension\ContentDimensionSourceInterface;
use Neos\ContentRepository\Core\DimensionSpace\ContentDimensionZookeeper;
use Neos\ContentRepository\Core\DimensionSpace\InterDimensionalVariationGraph;
use Neos\ContentRepository\Core\EventStore\EventNormalizer;
use Neos\ContentRepository\Core\EventStore\EventPersister;
use Neos\ContentRepository\Core\Feature\ContentStreamCommandHandler;
use Neos\ContentRepository\Core\Feature\DimensionSpaceAdjustment\DimensionSpaceCommandHandler;
use Neos\ContentRepository\Core\Feature\NodeAggregateCommandHandler;
use Neos\ContentRepository\Core\Feature\NodeDuplication\NodeDuplicationCommandHandler;
use Neos\ContentRepository\Core\Feature\WorkspaceCommandHandler;
use Neos\ContentRepository\Core\Infrastructure\Property\PropertyConverter;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ProjectionEventHandler;
use Neos\ContentRepository\Core\Projection\ProjectionStates;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\User\UserIdProviderInterface;
use Neos\ContentRepository\Core\Subscription\Engine\SubscriptionEngine;
use Neos\ContentRepository\Core\Subscription\EventStore\RunSubscriptionEventStore;
use Neos\ContentRepository\Core\Subscription\RetryStrategy\NoRetryStrategy;
use Neos\ContentRepository\Core\Subscription\RunMode;
use Neos\ContentRepository\Core\Subscription\Store\SubscriptionStoreInterface;
use Neos\ContentRepository\Core\Subscription\Subscriber\Subscriber;
use Neos\ContentRepository\Core\Subscription\Subscriber\Subscribers;
use Neos\ContentRepository\Core\Subscription\SubscriptionGroup;
use Neos\ContentRepository\Core\Subscription\SubscriptionId;
use Neos\EventStore\EventStoreInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Serializer\Serializer;

/**
 * Main factory to build a {@see ContentRepository} object.
 *
 * @api
 */
final class ContentRepositoryFactory
{
    private ProjectionFactoryDependencies $projectionFactoryDependencies;
    private EventStoreInterface $eventStore;

    private SubscriptionEngine $subscriptionEngine;

    public function __construct(
        private readonly ContentRepositoryId $contentRepositoryId,
        EventStoreInterface $eventStore,
        NodeTypeManager $nodeTypeManager,
        ContentDimensionSourceInterface $contentDimensionSource,
        Serializer $propertySerializer,
        private readonly UserIdProviderInterface $userIdProvider,
        private readonly ClockInterface $clock,
        SubscriptionStoreInterface $subscriptionStore,
    ) {
        $contentDimensionZookeeper = new ContentDimensionZookeeper($contentDimensionSource);
        $interDimensionalVariationGraph = new InterDimensionalVariationGraph(
            $contentDimensionSource,
            $contentDimensionZookeeper
        );
        $eventNormalizer = new EventNormalizer();
        $this->projectionFactoryDependencies = new ProjectionFactoryDependencies(
            $contentRepositoryId,
            $eventNormalizer,
            $nodeTypeManager,
            $contentDimensionSource,
            $contentDimensionZookeeper,
            $interDimensionalVariationGraph,
            new PropertyConverter($propertySerializer)
        );
        $subscribers = [];
        foreach ($this->projectionsAndCatchUpHooks->projections as $projection) {
            $subscribers[] = new Subscriber(
                SubscriptionId::fromString(substr(strrchr($projection::class, '\\'), 1)),
                SubscriptionGroup::fromString('default'),
                RunMode::FROM_BEGINNING,
                new ProjectionEventHandler(
                    $projection,
                    $this->projectionsAndCatchUpHooks->getCatchUpHookFactoryForProjection($projection)?->build($this->getOrBuild()),
                ),
            );
        }
        $this->subscriptionEngine = new SubscriptionEngine($eventStore, $subscriptionStore, Subscribers::fromArray($subscribers), $eventNormalizer, new NoRetryStrategy());
        $this->eventStore = new RunSubscriptionEventStore($eventStore, $this->subscriptionEngine);
    }

    // The following properties store "singleton" references of objects for this content repository
    private ?ContentRepository $contentRepository = null;
    private ?EventPersister $eventPersister = null;

    /**
     * Builds and returns the content repository. If it is already built, returns the same instance.
     *
     * @return ContentRepository
     * @api
     */
    public function getOrBuild(): ContentRepository
    {
        if ($this->contentRepository) {
            return $this->contentRepository;
        }

        $contentGraphReadModel = $this->projectionsAndCatchUpHooks->contentGraphProjection->getState();
        $commandHandlingDependencies = new CommandHandlingDependencies($contentGraphReadModel);

        // we dont need full recursion in rebase - e.g apply workspace commands - and thus we can use this set for simulation
        $commandBusForRebaseableCommands = new CommandBus(
            $commandHandlingDependencies,
            new NodeAggregateCommandHandler(
                $this->projectionFactoryDependencies->nodeTypeManager,
                $this->projectionFactoryDependencies->contentDimensionZookeeper,
                $this->projectionFactoryDependencies->interDimensionalVariationGraph,
                $this->projectionFactoryDependencies->propertyConverter,
            ),
            new DimensionSpaceCommandHandler(
                $this->projectionFactoryDependencies->contentDimensionZookeeper,
                $this->projectionFactoryDependencies->interDimensionalVariationGraph,
            ),
            new NodeDuplicationCommandHandler(
                $this->projectionFactoryDependencies->nodeTypeManager,
                $this->projectionFactoryDependencies->contentDimensionZookeeper,
                $this->projectionFactoryDependencies->interDimensionalVariationGraph,
            )
        );

        $commandSimulatorFactory = new CommandSimulatorFactory(
            $this->projectionsAndCatchUpHooks->contentGraphProjection,
            $this->projectionFactoryDependencies->eventNormalizer,
            $commandBusForRebaseableCommands
        );

        $publicCommandBus = $commandBusForRebaseableCommands->withAdditionalHandlers(
            new ContentStreamCommandHandler(),
            new WorkspaceCommandHandler(
                $commandSimulatorFactory,
                $this->eventStore,
                $this->projectionFactoryDependencies->eventNormalizer,
            )
        );

        $projectionStates = [];
        foreach ($this->projectionsAndCatchUpHooks->projections as $projection) {
            $projectionStates[] = $projection->getState();
        }

        return $this->contentRepository = new ContentRepository(
            $this->contentRepositoryId,
            $publicCommandBus,
            $this->buildEventPersister(),
            $this->projectionFactoryDependencies->nodeTypeManager,
            $this->projectionFactoryDependencies->interDimensionalVariationGraph,
            $this->projectionFactoryDependencies->contentDimensionSource,
            $this->userIdProvider,
            $this->clock,
            $contentGraphReadModel,
            ProjectionStates::fromArray($projectionStates),
        );
    }

    /**
     * A service is a high-level "application part" which builds upon the CR internals.
     *
     * You don't usually need this yourself, but it is usually enough to simply use the {@see ContentRepository}
     * instance. If you want to extend the CR core and need to hook deeply into CR internals, this is what the
     * {@see ContentRepositoryServiceInterface} is for.
     *
     * @template T of ContentRepositoryServiceInterface
     * @param ContentRepositoryServiceFactoryInterface<T> $serviceFactory
     * @return T
     */
    public function buildService(
        ContentRepositoryServiceFactoryInterface $serviceFactory
    ): ContentRepositoryServiceInterface {

        $serviceFactoryDependencies = ContentRepositoryServiceFactoryDependencies::create(
            $this->projectionFactoryDependencies,
            $this->eventStore,
            $this->getOrBuild(),
            $this->buildEventPersister(),
            $this->subscriptionEngine,
        );
        return $serviceFactory->build($serviceFactoryDependencies);
    }

    private function buildEventPersister(): EventPersister
    {
        if (!$this->eventPersister) {
            $this->eventPersister = new EventPersister(
                $this->eventStore,
                $this->projectionFactoryDependencies->eventNormalizer,
            );
        }
        return $this->eventPersister;
    }
}
