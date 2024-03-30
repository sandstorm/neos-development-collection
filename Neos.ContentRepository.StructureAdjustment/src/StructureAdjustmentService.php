<?php

declare(strict_types=1);

namespace Neos\ContentRepository\StructureAdjustment;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\InterDimensionalVariationGraph;
use Neos\ContentRepository\Core\EventStore\DecoratedEvent;
use Neos\ContentRepository\Core\EventStore\EventInterface;
use Neos\ContentRepository\Core\EventStore\EventNormalizer;
use Neos\ContentRepository\Core\EventStore\Events;
use Neos\ContentRepository\Core\EventStore\EventsToPublish;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceInterface;
use Neos\ContentRepository\Core\Infrastructure\Property\PropertyConverter;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Core\Subscription\Engine\SubscriptionEngine;
use Neos\ContentRepository\StructureAdjustment\Adjustment\DimensionAdjustment;
use Neos\ContentRepository\StructureAdjustment\Adjustment\DisallowedChildNodeAdjustment;
use Neos\ContentRepository\StructureAdjustment\Adjustment\PropertyAdjustment;
use Neos\ContentRepository\StructureAdjustment\Adjustment\StructureAdjustment;
use Neos\ContentRepository\StructureAdjustment\Adjustment\TetheredNodeAdjustments;
use Neos\ContentRepository\StructureAdjustment\Adjustment\UnknownNodeTypeAdjustment;
use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Model\Event\EventId;
use Neos\EventStore\Model\Event\EventMetadata;

class StructureAdjustmentService implements ContentRepositoryServiceInterface
{
    protected TetheredNodeAdjustments $tetheredNodeAdjustments;
    protected UnknownNodeTypeAdjustment $unknownNodeTypeAdjustment;
    protected DisallowedChildNodeAdjustment $disallowedChildNodeAdjustment;
    protected PropertyAdjustment $propertyAdjustment;
    protected DimensionAdjustment $dimensionAdjustment;

    /**
     * Content graph bound to the live workspace to iterate over the "real" Nodes; that is, the nodes,
     * which have an entry in the Graph Projection's "node" table.
     *
     * @var ContentGraphInterface
     */
    private readonly ContentGraphInterface $liveContentGraph;

    public function __construct(
        ContentRepository $contentRepository,
        private readonly EventStoreInterface $eventStore,
        private readonly EventNormalizer $eventNormalizer,
        private readonly SubscriptionEngine $subscriptionEngine,
        NodeTypeManager $nodeTypeManager,
        InterDimensionalVariationGraph $interDimensionalVariationGraph,
        PropertyConverter $propertyConverter,
    ) {

        $this->liveContentGraph = $contentRepository->getContentGraph(WorkspaceName::forLive());

        $this->tetheredNodeAdjustments = new TetheredNodeAdjustments(
            $this->liveContentGraph,
            $nodeTypeManager,
            $interDimensionalVariationGraph,
            $propertyConverter,
        );

        $this->unknownNodeTypeAdjustment = new UnknownNodeTypeAdjustment(
            $this->liveContentGraph,
            $nodeTypeManager
        );
        $this->disallowedChildNodeAdjustment = new DisallowedChildNodeAdjustment(
            $this->liveContentGraph,
            $nodeTypeManager
        );
        $this->propertyAdjustment = new PropertyAdjustment(
            $this->liveContentGraph,
            $nodeTypeManager
        );
        $this->dimensionAdjustment = new DimensionAdjustment(
            $this->liveContentGraph,
            $interDimensionalVariationGraph,
            $nodeTypeManager
        );
    }

    /**
     * @return \Generator|StructureAdjustment[]
     */
    public function findAllAdjustments(): \Generator
    {
        foreach ($this->liveContentGraph->findUsedNodeTypeNames() as $nodeTypeName) {
            yield from $this->findAdjustmentsForNodeType($nodeTypeName);
        }
    }

    /**
     * @param NodeTypeName $nodeTypeName
     * @return \Generator|StructureAdjustment[]
     */
    public function findAdjustmentsForNodeType(NodeTypeName $nodeTypeName): \Generator
    {
        yield from $this->tetheredNodeAdjustments->findAdjustmentsForNodeType($nodeTypeName);
        yield from $this->unknownNodeTypeAdjustment->findAdjustmentsForNodeType($nodeTypeName);
        yield from $this->disallowedChildNodeAdjustment->findAdjustmentsForNodeType($nodeTypeName);
        yield from $this->propertyAdjustment->findAdjustmentsForNodeType($nodeTypeName);
        yield from $this->dimensionAdjustment->findAdjustmentsForNodeType($nodeTypeName);
    }

    public function fixError(StructureAdjustment $adjustment): void
    {
        if (!$adjustment->remediation) {
            return;
        }
        $remediation = $adjustment->remediation;
        $eventsToPublish = $remediation();
        assert($eventsToPublish instanceof EventsToPublish);

        $eventsWithMetaData = self::eventsWithCausationOfFirstEventAndAdditionalMetaData(
            $eventsToPublish->events,
            EventMetadata::fromArray([
                'structureAdjustment' => mb_strimwidth($adjustment->render() , 0, 250, '…')
            ])
        );

        $normalizedEvents = \Neos\EventStore\Model\Events::fromArray(
            $eventsWithMetaData->map($this->eventNormalizer->normalize(...))
        );
        $this->eventStore->commit(
            $eventsToPublish->streamName,
            $normalizedEvents,
            $eventsToPublish->expectedVersion
        );
        $this->subscriptionEngine->catchUpActive();
    }

    private static function eventsWithCausationOfFirstEventAndAdditionalMetaData(Events $events, EventMetadata $metadata): Events
    {
        /** @var non-empty-list<EventInterface|DecoratedEvent> $restEvents */
        $restEvents = iterator_to_array($events);
        $firstEvent = array_shift($restEvents);

        if ($firstEvent instanceof DecoratedEvent && $firstEvent->eventMetadata) {
            $metadata = EventMetadata::fromArray(array_merge($firstEvent->eventMetadata->value, $metadata->value));
        }

        $decoratedFirstEvent = DecoratedEvent::create($firstEvent, eventId: EventId::create(), metadata: $metadata);

        $decoratedRestEvents = [];
        foreach ($restEvents as $event) {
            $decoratedRestEvents[] = DecoratedEvent::create($event, causationId: $decoratedFirstEvent->eventId);
        }

        return Events::fromArray([$decoratedFirstEvent, ...$decoratedRestEvents]);
    }
}
