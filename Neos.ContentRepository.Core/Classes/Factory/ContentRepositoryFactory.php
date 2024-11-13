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
use Neos\ContentRepository\Core\Feature\DimensionSpaceAdjustment\DimensionSpaceCommandHandler;
use Neos\ContentRepository\Core\Feature\NodeAggregateCommandHandler;
use Neos\ContentRepository\Core\Feature\NodeDuplication\NodeDuplicationCommandHandler;
use Neos\ContentRepository\Core\Feature\WorkspaceCommandHandler;
use Neos\ContentRepository\Core\Infrastructure\Property\PropertyConverter;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\CatchUpHook\CatchUpHookFactoryDependencies;
use Neos\ContentRepository\Core\Projection\CatchUpHook\CatchUpHookFactoryInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphProjectionFactoryInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphProjectionInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphReadModelInterface;
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
    private SubscriberFactoryDependencies $subscriberFactoryDependencies;
    private EventStoreInterface $eventStore;
    private SubscriptionEngine $subscriptionEngine;
    private ContentGraphProjectionInterface $contentGraphProjection;
    private ProjectionStates $additionalProjectionStates;

    // The following properties store "singleton" references of objects for this content repository
    private ?ContentRepository $contentRepositoryRuntimeCache = null;
    private ?EventPersister $eventPersisterRuntimeCache = null;

    /**
     * @param CatchUpHookFactoryInterface<ContentGraphReadModelInterface> $contentGraphCatchUpHookFactory
     */
    public function __construct(
        private readonly ContentRepositoryId $contentRepositoryId,
        EventStoreInterface $eventStore,
        NodeTypeManager $nodeTypeManager,
        ContentDimensionSourceInterface $contentDimensionSource,
        Serializer $propertySerializer,
        private readonly UserIdProviderInterface $userIdProvider,
        private readonly ClockInterface $clock,
        SubscriptionStoreInterface $subscriptionStore,
        ContentGraphProjectionFactoryInterface $contentGraphProjectionFactory,
        private readonly CatchUpHookFactoryInterface $contentGraphCatchUpHookFactory,
        private readonly ContentRepositorySubscriberFactories $additionalSubscriberFactories,
    ) {
        $contentDimensionZookeeper = new ContentDimensionZookeeper($contentDimensionSource);
        $interDimensionalVariationGraph = new InterDimensionalVariationGraph(
            $contentDimensionSource,
            $contentDimensionZookeeper
        );
        $eventNormalizer = new EventNormalizer();
        $this->subscriberFactoryDependencies = new SubscriberFactoryDependencies(
            $contentRepositoryId,
            $eventNormalizer,
            $nodeTypeManager,
            $contentDimensionSource,
            $contentDimensionZookeeper,
            $interDimensionalVariationGraph,
            new PropertyConverter($propertySerializer)
        );
        $subscribers = [$this->buildContentGraphSubscriber()];
        $additionalProjectionStates = [];
        foreach ($this->additionalSubscriberFactories as $additionalSubscriberFactory) {
            $subscriber = $additionalSubscriberFactory->build($this->subscriberFactoryDependencies);
            if ($subscriber->handler instanceof ProjectionEventHandler) {
                $additionalProjectionStates[] = $subscriber->handler->projection->getState();
            }
            $subscribers[] = $subscriber;
        }
        $this->additionalProjectionStates = ProjectionStates::fromArray($additionalProjectionStates);
        $this->contentGraphProjection = $contentGraphProjectionFactory->build($this->subscriberFactoryDependencies);
        $this->subscriptionEngine = new SubscriptionEngine($eventStore, $subscriptionStore, Subscribers::fromArray($subscribers), $eventNormalizer, new NoRetryStrategy());
        $this->eventStore = new RunSubscriptionEventStore($eventStore, $this->subscriptionEngine);
    }

    private function buildContentGraphSubscriber(): Subscriber
    {
        return new Subscriber(
            SubscriptionId::fromString('contentGraph'),
            SubscriptionGroup::fromString('default'),
            RunMode::FROM_BEGINNING,
            ProjectionEventHandler::createWithCatchUpHook(
                $this->contentGraphProjection,
                $this->contentGraphCatchUpHookFactory->build(new CatchUpHookFactoryDependencies(
                    $this->contentRepositoryId,
                    $this->contentGraphProjection->getState(),
                    $this->subscriberFactoryDependencies->nodeTypeManager,
                    $this->subscriberFactoryDependencies->contentDimensionSource,
                    $this->subscriberFactoryDependencies->interDimensionalVariationGraph,
                )),
            ),
        );
    }

    /**
     * Builds and returns the content repository. If it is already built, returns the same instance.
     *
     * @return ContentRepository
     * @api
     */
    public function getOrBuild(): ContentRepository
    {
        if ($this->contentRepositoryRuntimeCache) {
            return $this->contentRepositoryRuntimeCache;
        }

        $contentGraphReadModel = $this->contentGraphProjection->getState();
        $commandHandlingDependencies = new CommandHandlingDependencies($contentGraphReadModel);

        // we dont need full recursion in rebase - e.g apply workspace commands - and thus we can use this set for simulation
        $commandBusForRebaseableCommands = new CommandBus(
            $commandHandlingDependencies,
            new NodeAggregateCommandHandler(
                $this->subscriberFactoryDependencies->nodeTypeManager,
                $this->subscriberFactoryDependencies->contentDimensionZookeeper,
                $this->subscriberFactoryDependencies->interDimensionalVariationGraph,
                $this->subscriberFactoryDependencies->propertyConverter,
            ),
            new DimensionSpaceCommandHandler(
                $this->subscriberFactoryDependencies->contentDimensionZookeeper,
                $this->subscriberFactoryDependencies->interDimensionalVariationGraph,
            ),
            new NodeDuplicationCommandHandler(
                $this->subscriberFactoryDependencies->nodeTypeManager,
                $this->subscriberFactoryDependencies->contentDimensionZookeeper,
                $this->subscriberFactoryDependencies->interDimensionalVariationGraph,
            )
        );

        $commandSimulatorFactory = new CommandSimulatorFactory(
            $this->contentGraphProjection,
            $this->subscriberFactoryDependencies->eventNormalizer,
            $commandBusForRebaseableCommands
        );

        $publicCommandBus = $commandBusForRebaseableCommands->withAdditionalHandlers(
            new WorkspaceCommandHandler(
                $commandSimulatorFactory,
                $this->eventStore,
                $this->subscriberFactoryDependencies->eventNormalizer,
            )
        );

        return $this->contentRepositoryRuntimeCache = new ContentRepository(
            $this->contentRepositoryId,
            $publicCommandBus,
            $this->buildEventPersister(),
            $this->subscriberFactoryDependencies->nodeTypeManager,
            $this->subscriberFactoryDependencies->interDimensionalVariationGraph,
            $this->subscriberFactoryDependencies->contentDimensionSource,
            $this->userIdProvider,
            $this->clock,
            $contentGraphReadModel,
            $this->additionalProjectionStates,
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
            $this->subscriberFactoryDependencies,
            $this->eventStore,
            $this->getOrBuild(),
            $this->buildEventPersister(),
            $this->subscriptionEngine,
        );
        return $serviceFactory->build($serviceFactoryDependencies);
    }

    private function buildEventPersister(): EventPersister
    {
        if (!$this->eventPersisterRuntimeCache) {
            $this->eventPersisterRuntimeCache = new EventPersister(
                $this->eventStore,
                $this->subscriberFactoryDependencies->eventNormalizer,
            );
        }
        return $this->eventPersisterRuntimeCache;
    }
}
