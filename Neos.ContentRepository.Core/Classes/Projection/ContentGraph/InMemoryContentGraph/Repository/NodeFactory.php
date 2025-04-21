<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Projection\ContentGraph\InMemoryContentGraph\Repository;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePointSet;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePointSet;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Dto\SubtreeTags;
use Neos\ContentRepository\Core\Infrastructure\Property\PropertyConverter;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\CoverageByOrigin;
use Neos\ContentRepository\Core\Projection\ContentGraph\InMemoryContentGraph\Projection\InMemoryNodeRecord;
use Neos\ContentRepository\Core\Projection\ContentGraph\InMemoryContentGraph\Projection\InMemoryNodeRecords;
use Neos\ContentRepository\Core\Projection\ContentGraph\InMemoryContentGraph\Projection\InMemoryReferenceRecord;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeAggregate;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeAggregates;
use Neos\ContentRepository\Core\Projection\ContentGraph\Nodes;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeTags;
use Neos\ContentRepository\Core\Projection\ContentGraph\OriginByCoverage;
use Neos\ContentRepository\Core\Projection\ContentGraph\PropertyCollection;
use Neos\ContentRepository\Core\Projection\ContentGraph\Reference;
use Neos\ContentRepository\Core\Projection\ContentGraph\References;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeTypeNotFound;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateClassification;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;

/**
 * Implementation detail of ContentGraph and ContentSubgraph
 *
 * @internal
 */
final class NodeFactory
{
    public function __construct(
        private readonly ContentRepositoryId $contentRepositoryId,
        private readonly PropertyConverter $propertyConverter,
    ) {
    }

    public function mapNodeRecordToNode(
        InMemoryNodeRecord $nodeRecord,
        WorkspaceName $workspaceName,
        DimensionSpacePoint $dimensionSpacePoint,
        VisibilityConstraints $visibilityConstraints,
    ): Node {
        return Node::create(
            $this->contentRepositoryId,
            $workspaceName,
            $dimensionSpacePoint,
            $nodeRecord->nodeAggregateId,
            $nodeRecord->originDimensionSpacePoint,
            $nodeRecord->classification,
            $nodeRecord->nodeTypeName,
            new PropertyCollection(
                $nodeRecord->properties,
                $this->propertyConverter
            ),
            $nodeRecord->name,
            NodeTags::create(
                $nodeRecord->tags,
                $nodeRecord->inheritedTags,
            ),
            $nodeRecord->timestamps,
            $visibilityConstraints
        );
    }

    /**
     * @param iterable<int, InMemoryNodeRecord> $nodeRecords
     */
    public function mapNodeRecordsToNodes(
        iterable $nodeRecords,
        WorkspaceName $workspaceName,
        DimensionSpacePoint $dimensionSpacePoint,
        VisibilityConstraints $visibilityConstraints
    ): Nodes {
        return Nodes::fromArray(
            array_map(
                fn (InMemoryNodeRecord $nodeRecord) => $this->mapNodeRecordToNode(
                    $nodeRecord,
                    $workspaceName,
                    $dimensionSpacePoint,
                    $visibilityConstraints
                ),
                iterator_to_array($nodeRecords)
            )
        );
    }

    /**
     * @param array<int,InMemoryReferenceRecord> $referenceRecords
     */
    public function mapReferenceRecordsToReferences(
        array $referenceRecords,
        WorkspaceName $workspaceName,
        DimensionSpacePoint $dimensionSpacePoint,
        VisibilityConstraints $visibilityConstraints,
        bool $backwards,
    ): References {
        $result = [];
        foreach ($referenceRecords as $referenceRecord) {
            $node = $this->mapNodeRecordToNode(
                $backwards ? $referenceRecord->source : $referenceRecord->target,
                $workspaceName,
                $dimensionSpacePoint,
                $visibilityConstraints
            );
            $result[] = new Reference(
                $node,
                $referenceRecord->name,
                $referenceRecord->properties
                    ? new PropertyCollection(
                        $referenceRecord->properties,
                        $this->propertyConverter
                    )
                    : null,
            );
        }

        return References::fromArray($result);
    }

    /**
     * @param non-empty-array<int, InMemoryNodeRecord> $nodeRecords
     */
    public function mapNodeRecordsToNodeAggregate(
        array $nodeRecords,
        WorkspaceName $workspaceName,
        ContentStreamId $contentStreamId
    ): NodeAggregate {
        $nodeAggregateId = null;
        $classification = null;
        $nodeTypeName = null;
        $nodeName = null;
        $occupiedDimensionSpacePoints = [];
        $nodesByOccupiedDimensionSpacePoint = [];
        $coverageByOccupant = [];
        $totalCoveredDimensionSpacePoints = DimensionSpacePointSet::fromArray([]);
        $occupationByCovered = [];
        $nodeTagsByCoveredDimensionSpacePoint = [];
        foreach ($nodeRecords as $nodeRecord) {
            $nodeAggregateId = $nodeRecord->nodeAggregateId;
            $classification = $nodeRecord->classification;
            $nodeTypeName = $nodeRecord->nodeTypeName;
            $nodeName = $nodeRecord->name;
            $occupiedDimensionSpacePoints[] = $nodeRecord->originDimensionSpacePoint;
            $nodesByOccupiedDimensionSpacePoint[$nodeRecord->originDimensionSpacePoint->hash] = $this->mapNodeRecordToNode(
                $nodeRecord,
                $workspaceName,
                $nodeRecord->originDimensionSpacePoint->toDimensionSpacePoint(),
                VisibilityConstraints::createEmpty()
            );
            $coveredDimensionSpacePoints = $nodeRecord->parentsByContentStreamId[$contentStreamId->value]->getCoveredDimensionSpacePointSet();
            $coverageByOccupant[$nodeRecord->originDimensionSpacePoint->hash] = iterator_to_array($coveredDimensionSpacePoints);
            $totalCoveredDimensionSpacePoints = $totalCoveredDimensionSpacePoints->getUnion($coveredDimensionSpacePoints);
            foreach ($coveredDimensionSpacePoints as $coveredDimensionSpacePoint) {
                $occupationByCovered[$coveredDimensionSpacePoint->hash] = $nodeRecord->originDimensionSpacePoint;
                $nodeTagsByCoveredDimensionSpacePoint[$coveredDimensionSpacePoint->hash] = NodeTags::create(
                    $nodeRecord->tags,
                    $nodeRecord->inheritedTags
                );
            }
        }

        return NodeAggregate::create(
            $this->contentRepositoryId,
            $workspaceName,
            $nodeAggregateId,
            $classification,
            $nodeTypeName,
            $nodeName,
            new OriginDimensionSpacePointSet($occupiedDimensionSpacePoints),
            $nodesByOccupiedDimensionSpacePoint,
            CoverageByOrigin::fromArray($coverageByOccupant),
            $totalCoveredDimensionSpacePoints,
            OriginByCoverage::fromArray($occupationByCovered),
            /** @phpstan-ignore argument.type (never empty) */
            $nodeTagsByCoveredDimensionSpacePoint,
        );
    }

    /**
     * @param array<int,array<string,string>> $nodeRows
     * @deprecated
     */
    public function mapNodeRowsToNodeAggregate(
        array $nodeRows,
        WorkspaceName $workspaceName,
        VisibilityConstraints $visibilityConstraints
    ): ?NodeAggregate {
        return null;
    }

    /**
     * @param array<int,array<string,string>> $nodeRows
     * @deprecated
     */
    public function mapNodeRowsToNodeAggregates(
        array $nodeRows,
        WorkspaceName $workspaceName,
        VisibilityConstraints $visibilityConstraints
    ): NodeAggregates {
        return NodeAggregates::createEmpty();
    }

    public static function extractNodeTagsFromJson(string $subtreeTagsJson): NodeTags
    {
        $explicitTags = [];
        $inheritedTags = [];
        try {
            $subtreeTagsArray = json_decode($subtreeTagsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('Failed to JSON-decode subtree tags from JSON string %s: %s', $subtreeTagsJson, $e->getMessage()), 1716476904, $e);
        }
        foreach ($subtreeTagsArray as $tagValue => $explicit) {
            if ($explicit) {
                $explicitTags[] = $tagValue;
            } else {
                $inheritedTags[] = $tagValue;
            }
        }
        return NodeTags::create(
            tags: SubtreeTags::fromStrings(...$explicitTags),
            inheritedTags: SubtreeTags::fromStrings(...$inheritedTags)
        );
    }
}
