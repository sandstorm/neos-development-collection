<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Projection\ContentGraph\InMemoryContentGraph\Repository;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePointSet;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePointSet;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Dto\SubtreeTag;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\NodeType\NodeTypeNames;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\CoverageByOrigin;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindRootNodeAggregatesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\InMemoryContentGraph\Projection\InMemoryContentGraphStructure;
use Neos\ContentRepository\Core\Projection\ContentGraph\InMemoryContentGraph\Projection\InMemoryNodeRecord;
use Neos\ContentRepository\Core\Projection\ContentGraph\InMemoryContentGraph\Projection\InMemoryNodeRecords;
use Neos\ContentRepository\Core\Projection\ContentGraph\InMemoryContentGraph\Projection\NullNodeRecord;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeAggregate;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeAggregates;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeTags;
use Neos\ContentRepository\Core\Projection\ContentGraph\OriginByCoverage;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateClassification;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateIds;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;

/**
 * The In-Memory adapter content graph
 *
 * To be used as a read-only source of nodes
 *
 * @internal the parent interface {@see ContentGraphInterface} is API
 */
final class InMemoryContentGraph implements ContentGraphInterface
{
    public function __construct(
        private readonly InMemoryContentGraphStructure $graphStructure,
        private readonly NodeFactory $nodeFactory,
        private readonly ContentRepositoryId $contentRepositoryId,
        private readonly NodeTypeManager $nodeTypeManager,
        public readonly WorkspaceName $workspaceName,
        public readonly ContentStreamId $contentStreamId
    ) {
    }

    public function getContentRepositoryId(): ContentRepositoryId
    {
        return $this->contentRepositoryId;
    }

    public function getWorkspaceName(): WorkspaceName
    {
        return $this->workspaceName;
    }

    public function getSubgraph(
        DimensionSpacePoint $dimensionSpacePoint,
        VisibilityConstraints $visibilityConstraints
    ): ContentSubgraphInterface {
        return new InMemoryContentSubgraph(
            $this->contentRepositoryId,
            $this->workspaceName,
            $this->contentStreamId,
            $dimensionSpacePoint,
            $visibilityConstraints,
            $this->nodeFactory,
            $this->nodeTypeManager,
            $this->graphStructure,
        );
    }

    public function findRootNodeAggregateByType(
        NodeTypeName $nodeTypeName
    ): ?NodeAggregate {
        $rootNodeAggregates = $this->findRootNodeAggregates(
            FindRootNodeAggregatesFilter::create(nodeTypeName: $nodeTypeName)
        );

        if ($rootNodeAggregates->count() > 1) {
            // todo drop this check as this is enforced by the write side? https://github.com/neos/neos-development-collection/pull/4339
            $ids = [];
            foreach ($rootNodeAggregates as $rootNodeAggregate) {
                $ids[] = $rootNodeAggregate->nodeAggregateId->value;
            }

            // We throw if multiple root node aggregates of the given $nodeTypeName were found,
            // as this would lead to nondeterministic results. Must not happen.
            throw new \RuntimeException(sprintf(
                'More than one root node aggregate of type "%s" found (IDs: %s).',
                $nodeTypeName->value,
                implode(', ', $ids)
            ));
        }

        return $rootNodeAggregates->first();
    }

    public function findRootNodeAggregates(
        FindRootNodeAggregatesFilter $filter,
    ): NodeAggregates {
        if ($filter->nodeTypeName) {
            $rootNodes = $this->graphStructure->rootNodes[$this->contentStreamId->value][$filter->nodeTypeName->value] ?? null;
            if ($rootNodes === null) {
                return NodeAggregates::createEmpty();
            }
            $rootNodeRecords = [$filter->nodeTypeName->value => [$rootNodes]];
        } else {
            $rootNodeRecords = $this->graphStructure->rootNodes;
        }
        return NodeAggregates::fromArray(
            array_map(
                fn (array $nodeRecords): NodeAggregate => $this->nodeFactory->mapNodeRecordsToNodeAggregate($nodeRecords, $this->workspaceName, $this->contentStreamId),
                $rootNodeRecords
            )
        );
    }

    public function findNodeAggregatesByType(
        NodeTypeName $nodeTypeName
    ): NodeAggregates {
        $queryBuilder = $this->nodeQueryBuilder->buildBasicNodeAggregateQuery();
        $queryBuilder
            ->andWhere('n.nodetypename = :nodeTypeName')
            ->setParameters([
                'contentStreamId' => $this->contentStreamId->value,
                'nodeTypeName' => $nodeTypeName->value,
            ]);
        return $this->mapQueryBuilderToNodeAggregates($queryBuilder);
    }

    public function findNodeAggregateById(
        NodeAggregateId $nodeAggregateId
    ): ?NodeAggregate {
        $nodeRecords = $this->graphStructure->nodes[$this->contentStreamId->value][$nodeAggregateId->value] ?? null;
        return $nodeRecords === null
            ? null
            : $this->nodeFactory->mapNodeRecordsToNodeAggregate($nodeRecords, $this->workspaceName, $this->contentStreamId);
    }

    public function findNodeAggregatesByIds(
        NodeAggregateIds $nodeAggregateIds
    ): NodeAggregates {
        $nodeAggregates = [];
        foreach ($nodeAggregateIds as $nodeAggregateId) {
            $nodeAggregate = $this->findNodeAggregateById($nodeAggregateId);
            if ($nodeAggregate !== null) {
                $nodeAggregates[] = $nodeAggregate;
            }
        }

        return NodeAggregates::fromArray($nodeAggregates);
    }

    /**
     * Parent node aggregates can have a greater dimension space coverage than the given child.
     * Thus, it is not enough to just resolve them from the nodes and edges connected to the given child node aggregate.
     * Instead, we resolve all parent node aggregate ids instead and fetch the complete aggregates from there.
     */
    public function findParentNodeAggregates(
        NodeAggregateId $childNodeAggregateId
    ): NodeAggregates {
        $parentNodeAggregateIds = [];
        foreach ($this->graphStructure->nodes[$this->contentStreamId->value][$childNodeAggregateId->value] as $nodeRecord) {
            foreach ($nodeRecord->parentsByContentStreamId[$this->contentStreamId->value] ?? [] as $parentNodeRecord) {
                if ($parentNodeRecord instanceof NullNodeRecord) {
                    continue;
                }
                $parentNodeAggregateIds[$parentNodeRecord->nodeAggregateId->value] = $parentNodeRecord->nodeAggregateId;
            }
        }

        return $this->findNodeAggregatesByIds(NodeAggregateIds::create(...$parentNodeAggregateIds));
    }

    public function findAncestorNodeAggregateIds(NodeAggregateId $entryNodeAggregateId): NodeAggregateIds
    {
        $nodeAggregateIds = NodeAggregateIds::create();
        foreach ($this->graphStructure->nodes[$this->contentStreamId->value][$entryNodeAggregateId->value] as $nodeRecord) {
            foreach ($nodeRecord->parentsByContentStreamId[$this->contentStreamId->value] ?? [] as $parentNodeRecord) {
                if ($parentNodeRecord instanceof NullNodeRecord) {
                    continue;
                }
                $nodeAggregateIds = $nodeAggregateIds->merge(NodeAggregateIds::create($parentNodeRecord->nodeAggregateId));
                $nodeAggregateIds = $nodeAggregateIds->merge($this->findAncestorNodeAggregateIds($parentNodeRecord->nodeAggregateId));
            }
        }

        return $nodeAggregateIds;
    }

    public function findChildNodeAggregates(
        NodeAggregateId $parentNodeAggregateId
    ): NodeAggregates {
        $childNodeAggregateIds = [];
        foreach ($this->graphStructure->nodes[$this->contentStreamId->value][$parentNodeAggregateId->value] as $nodeRecord) {
            foreach ($nodeRecord->childrenByContentStream[$this->contentStreamId->value] ?? [] as $children) {
                foreach ($children as $childNodeRecord) {
                    $childNodeAggregateIds[$childNodeRecord->nodeAggregateId->value] = $childNodeRecord->nodeAggregateId;
                }
            }
        }

        return $this->findNodeAggregatesByIds(NodeAggregateIds::create(...$childNodeAggregateIds));
    }

    public function findParentNodeAggregateByChildOriginDimensionSpacePoint(NodeAggregateId $childNodeAggregateId, OriginDimensionSpacePoint $childOriginDimensionSpacePoint): ?NodeAggregate
    {
        $subQueryBuilder = $this->createQueryBuilder()
            ->select('pn.nodeaggregateid')
            ->from($this->nodeQueryBuilder->tableNames->node(), 'pn')
            ->innerJoin('pn', $this->nodeQueryBuilder->tableNames->hierarchyRelation(), 'ch', 'ch.parentnodeanchor = pn.relationanchorpoint')
            ->innerJoin('ch', $this->nodeQueryBuilder->tableNames->node(), 'cn', 'cn.relationanchorpoint = ch.childnodeanchor')
            ->where('ch.contentstreamid = :contentStreamId')
            ->andWhere('ch.dimensionspacepointhash = :childOriginDimensionSpacePointHash')
            ->andWhere('cn.nodeaggregateid = :childNodeAggregateId')
            ->andWhere('cn.origindimensionspacepointhash = :childOriginDimensionSpacePointHash');

        $queryBuilder = $this->createQueryBuilder()
            ->select('n.*, h.contentstreamid, h.subtreetags, dsp.dimensionspacepoint AS covereddimensionspacepoint')
            ->from($this->nodeQueryBuilder->tableNames->node(), 'n')
            ->innerJoin('n', $this->nodeQueryBuilder->tableNames->hierarchyRelation(), 'h', 'h.childnodeanchor = n.relationanchorpoint')
            ->innerJoin('h', $this->nodeQueryBuilder->tableNames->dimensionSpacePoints(), 'dsp', 'dsp.hash = h.dimensionspacepointhash')
            ->where('n.nodeaggregateid = (' . $subQueryBuilder->getSQL() . ')')
            ->andWhere('h.contentstreamid = :contentStreamId')
            ->setParameters([
                'contentStreamId' => $this->contentStreamId->value,
                'childNodeAggregateId' => $childNodeAggregateId->value,
                'childOriginDimensionSpacePointHash' => $childOriginDimensionSpacePoint->hash,
            ]);

        return $this->nodeFactory->mapNodeRowsToNodeAggregate(
            $this->fetchRows($queryBuilder),
            $this->workspaceName,
            VisibilityConstraints::createEmpty()
        );
    }

    public function findTetheredChildNodeAggregates(NodeAggregateId $parentNodeAggregateId): NodeAggregates
    {
        $queryBuilder = $this->nodeQueryBuilder->buildChildNodeAggregateQuery($parentNodeAggregateId, $this->contentStreamId)
            ->andWhere('cn.classification = :tetheredClassification')
            ->setParameter('tetheredClassification', NodeAggregateClassification::CLASSIFICATION_TETHERED->value);

        return $this->mapQueryBuilderToNodeAggregates($queryBuilder);
    }

    public function findChildNodeAggregateByName(
        NodeAggregateId $parentNodeAggregateId,
        NodeName $name
    ): ?NodeAggregate {
        $queryBuilder = $this->nodeQueryBuilder->buildChildNodeAggregateQuery($parentNodeAggregateId, $this->contentStreamId)
            ->andWhere('cn.name = :relationName')
            ->setParameter('relationName', $name->value);

        return $this->mapQueryBuilderToNodeAggregate($queryBuilder);
    }

    public function getDimensionSpacePointsOccupiedByChildNodeName(NodeName $nodeName, NodeAggregateId $parentNodeAggregateId, OriginDimensionSpacePoint $parentNodeOriginDimensionSpacePoint, DimensionSpacePointSet $dimensionSpacePointsToCheck): DimensionSpacePointSet
    {
        $queryBuilder = $this->createQueryBuilder()
            ->select('dsp.dimensionspacepoint, h.dimensionspacepointhash')
            ->from($this->nodeQueryBuilder->tableNames->hierarchyRelation(), 'h')
            ->innerJoin('h', $this->nodeQueryBuilder->tableNames->node(), 'n', 'n.relationanchorpoint = h.parentnodeanchor')
            ->innerJoin('h', $this->nodeQueryBuilder->tableNames->dimensionSpacePoints(), 'dsp', 'dsp.hash = h.dimensionspacepointhash')
            ->innerJoin('n', $this->nodeQueryBuilder->tableNames->hierarchyRelation(), 'ph', 'ph.childnodeanchor = n.relationanchorpoint')
            ->where('n.nodeaggregateid = :parentNodeAggregateId')
            ->andWhere('n.origindimensionspacepointhash = :parentNodeOriginDimensionSpacePointHash')
            ->andWhere('ph.contentstreamid = :contentStreamId')
            ->andWhere('h.contentstreamid = :contentStreamId')
            ->andWhere('h.dimensionspacepointhash IN (:dimensionSpacePointHashes)')
            ->andWhere('n.name = :nodeName')
            ->setParameters([
                'parentNodeAggregateId' => $parentNodeAggregateId->value,
                'parentNodeOriginDimensionSpacePointHash' => $parentNodeOriginDimensionSpacePoint->hash,
                'contentStreamId' => $this->contentStreamId->value,
                'dimensionSpacePointHashes' => $dimensionSpacePointsToCheck->getPointHashes(),
                'nodeName' => $nodeName->value
            ], [
                'dimensionSpacePointHashes' => ArrayParameterType::STRING,
            ]);
        $dimensionSpacePoints = [];
        foreach ($this->fetchRows($queryBuilder) as $hierarchyRelationData) {
            $dimensionSpacePoints[$hierarchyRelationData['dimensionspacepointhash']] = DimensionSpacePoint::fromJsonString($hierarchyRelationData['dimensionspacepoint']);
        }

        return new DimensionSpacePointSet($dimensionSpacePoints);
    }

    public function findNodeAggregatesTaggedBy(SubtreeTag $subtreeTag): NodeAggregates
    {
        $queryBuilder =  $this->createQueryBuilder()
            ->select('n.*, h.contentstreamid, h.subtreetags, dsp.dimensionspacepoint AS covereddimensionspacepoint')
            // select the subtree tags from tagged (t) h and then join h again to fetch all node rows in that aggregate
            ->from($this->tableNames->hierarchyRelation(), 'th')
            ->innerJoin('th', $this->tableNames->hierarchyRelation(), 'h', 'th.childnodeanchor = h.childnodeanchor')
            ->innerJoin('h', $this->tableNames->node(), 'n', 'h.childnodeanchor = n.relationanchorpoint')
            ->innerJoin('h', $this->tableNames->dimensionSpacePoints(), 'dsp', 'dsp.hash = h.dimensionspacepointhash')
            ->where('th.contentstreamid = :contentStreamId')
            ->andWhere('JSON_EXTRACT(th.subtreetags, :tagPath) LIKE "true"')
            ->andWhere('h.contentstreamid = :contentStreamId')
            ->orderBy('n.relationanchorpoint', 'DESC')
            ->setParameters([
                'tagPath' => '$."' . $subtreeTag->value . '"',
                'contentStreamId' => $this->contentStreamId->value
            ]);

        return $this->mapQueryBuilderToNodeAggregates($queryBuilder);
    }

    public function findUsedNodeTypeNames(): NodeTypeNames
    {
        return NodeTypeNames::fromArray(array_map(
            static fn (array $row) => NodeTypeName::fromString($row['nodetypename']),
            $this->fetchRows($this->nodeQueryBuilder->buildFindUsedNodeTypeNamesQuery())
        ));
    }

    private function createQueryBuilder(): QueryBuilder
    {
        return $this->dbal->createQueryBuilder();
    }

    private function mapQueryBuilderToNodeAggregate(QueryBuilder $queryBuilder): ?NodeAggregate
    {
        return $this->nodeFactory->mapNodeRowsToNodeAggregate(
            $this->fetchRows($queryBuilder),
            $this->workspaceName,
            VisibilityConstraints::createEmpty()
        );
    }

    /**
     * @param QueryBuilder $queryBuilder
     * @return NodeAggregates
     */
    private function mapQueryBuilderToNodeAggregates(QueryBuilder $queryBuilder): NodeAggregates
    {
        return $this->nodeFactory->mapNodeRowsToNodeAggregates(
            $this->fetchRows($queryBuilder),
            $this->workspaceName,
            VisibilityConstraints::createEmpty()
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchRows(QueryBuilder $queryBuilder): array
    {
        try {
            return $queryBuilder->executeQuery()->fetchAllAssociative();
        } catch (DBALException $e) {
            throw new \RuntimeException(sprintf('Failed to fetch rows from database: %s', $e->getMessage()), 1701444358, $e);
        }
    }

    public function getContentStreamId(): ContentStreamId
    {
        return $this->contentStreamId;
    }
}
