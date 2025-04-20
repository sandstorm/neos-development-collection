<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Projection\ContentGraph\InMemoryContentGraph\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\AbsoluteNodePath;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\CountAncestorNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\CountBackReferencesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\CountChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\CountDescendantNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\CountReferencesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindAncestorNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindBackReferencesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindDescendantNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindPrecedingSiblingNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindReferencesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindSubtreeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindSucceedingSiblingNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType\ExpandedNodeTypeCriteria;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\Ordering\Ordering;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\Ordering\OrderingDirection;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\Ordering\TimestampField;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\Pagination\Pagination;
use Neos\ContentRepository\Core\Projection\ContentGraph\InMemoryContentGraph\Projection\InMemoryContentGraphStructure;
use Neos\ContentRepository\Core\Projection\ContentGraph\InMemoryContentGraph\Projection\InMemoryNodeRecord;
use Neos\ContentRepository\Core\Projection\ContentGraph\InMemoryContentGraph\Projection\InMemoryNodeRecords;
use Neos\ContentRepository\Core\Projection\ContentGraph\InMemoryContentGraph\Projection\InMemoryReferenceRecord;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodePath;
use Neos\ContentRepository\Core\Projection\ContentGraph\Nodes;
use Neos\ContentRepository\Core\Projection\ContentGraph\References;
use Neos\ContentRepository\Core\Projection\ContentGraph\Subtree;
use Neos\ContentRepository\Core\Projection\ContentGraph\Subtrees;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateClassification;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateIds;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyName;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;

/**
 * The in-memory content subgraph application repository
 *
 * To be used as a read-only source of nodes.
 *
 * @internal the parent {@see ContentSubgraphInterface} is API
 */
final class InMemoryContentSubgraph implements ContentSubgraphInterface
{
    public function __construct(
        private readonly ContentRepositoryId $contentRepositoryId,
        private readonly WorkspaceName $workspaceName,
        private readonly ContentStreamId $contentStreamId,
        private readonly DimensionSpacePoint $dimensionSpacePoint,
        private readonly VisibilityConstraints $visibilityConstraints,
        private readonly NodeFactory $nodeFactory,
        private readonly NodeTypeManager $nodeTypeManager,
        private readonly InMemoryContentGraphStructure $graphStructure,
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

    public function getDimensionSpacePoint(): DimensionSpacePoint
    {
        return $this->dimensionSpacePoint;
    }

    public function getVisibilityConstraints(): VisibilityConstraints
    {
        return $this->visibilityConstraints;
    }

    public function findChildNodes(NodeAggregateId $parentNodeAggregateId, FindChildNodesFilter $filter): Nodes
    {
        /** @todo apply filters */
        foreach ($this->graphStructure->nodes[$this->contentStreamId->value][$parentNodeAggregateId->value] as $parentNodeRecord) {
            if ($parentNodeRecord->coversDimensionSpacePoint($this->contentStreamId, $this->dimensionSpacePoint)) {
                $childRelations = $parentNodeRecord->childrenByContentStream[$this->contentStreamId->value];
                foreach ($childRelations as $childRecords) {
                    if ($childRelations->getInfo() === $this->dimensionSpacePoint) {
                        return $this->mapNodeRecordsToNodes($childRecords);
                    }
                }
            }
        }

        return Nodes::createEmpty();
    }

    public function countChildNodes(NodeAggregateId $parentNodeAggregateId, CountChildNodesFilter $filter): int
    {
        $queryBuilder = $this->buildChildNodesQuery($parentNodeAggregateId, $filter);
        return $this->fetchCount($queryBuilder);
    }

    public function findReferences(NodeAggregateId $nodeAggregateId, FindReferencesFilter $filter): References
    {
        /** @todo apply filters */
        $references = [];
        $referenceRelations = $this->graphStructure->references[$this->contentStreamId->value] ?? null;
        if ($referenceRelations === null) {
            return References::fromArray([]);
        }
        foreach ($referenceRelations as $referenceRecords) {
            if ($referenceRelations->getInfo() === $this->dimensionSpacePoint) {
                foreach ($referenceRecords as $referenceRecord) {
                    if ($referenceRecord->source->nodeAggregateId === $nodeAggregateId) {
                        $references[] = $this->mapReferenceRecordsToReferences($referenceRecord, false);
                    }
                }
                break;
            }
        }

        return References::fromArray($references);
    }

    public function countReferences(NodeAggregateId $nodeAggregateId, CountReferencesFilter $filter): int
    {
        return count($this->findReferences($nodeAggregateId, FindReferencesFilter::create(
            $filter->nodeTypes,
            $filter->nodeSearchTerm,
            $filter->nodePropertyValue,
            $filter->referenceSearchTerm,
            $filter->referencePropertyValue,
            $filter->referenceName,
        )));
    }

    public function findBackReferences(NodeAggregateId $nodeAggregateId, FindBackReferencesFilter $filter): References
    {
        /** @todo apply filters */
        $references = [];
        $referenceRelations = $this->graphStructure->references[$this->contentStreamId->value] ?? null;
        if ($referenceRelations === null) {
            return References::fromArray([]);
        }
        foreach ($referenceRelations as $referenceRecords) {
            if ($referenceRelations->getInfo() === $this->dimensionSpacePoint) {
                foreach ($referenceRecords as $referenceRecord) {
                    if ($referenceRecord->target->nodeAggregateId === $nodeAggregateId) {
                        $references[] = $this->mapReferenceRecordsToReferences($referenceRecord, true);
                    }
                }
                break;
            }
        }

        return References::fromArray($references);
    }

    public function countBackReferences(NodeAggregateId $nodeAggregateId, CountBackReferencesFilter $filter): int
    {
        return count($this->findBackReferences($nodeAggregateId, FindBackReferencesFilter::create(
            $filter->nodeTypes,
            $filter->nodeSearchTerm,
            $filter->nodePropertyValue,
            $filter->referenceSearchTerm,
            $filter->referencePropertyValue,
            $filter->referenceName,
        )));
    }

    public function findNodeById(NodeAggregateId $nodeAggregateId): ?Node
    {
        $nodeRecords = $this->graphStructure->nodes[$this->contentStreamId->value][$nodeAggregateId->value];
        foreach ($nodeRecords as $nodeRecord) {
            if ($nodeRecord->parentsByContentStreamId[$this->contentStreamId->value]->getCoveredDimensionSpacePointSet()->contains($this->dimensionSpacePoint)) {
                return $this->mapNodeRecordToNode($nodeRecord);
            }
        }

        return null;
    }

    public function findNodesByIds(NodeAggregateIds $nodeAggregateIds): Nodes
    {
        $queryBuilder = $this->nodeQueryBuilder->buildBasicNodeQuery($this->contentStreamId, $this->dimensionSpacePoint)
            ->andWhere('n.nodeaggregateid in (:nodeAggregateIds)')
            ->setParameter('nodeAggregateIds', $nodeAggregateIds->toStringArray(), ArrayParameterType::STRING);
        $this->addSubtreeTagConstraints($queryBuilder);
        return $this->fetchNodes($queryBuilder);
    }

    public function findRootNodeByType(NodeTypeName $nodeTypeName): ?Node
    {
        $queryBuilder = $this->nodeQueryBuilder->buildBasicNodeQuery($this->contentStreamId, $this->dimensionSpacePoint)
            ->andWhere('n.nodetypename = :nodeTypeName')->setParameter('nodeTypeName', $nodeTypeName->value)
            ->andWhere('n.classification = :nodeAggregateClassification')->setParameter('nodeAggregateClassification', NodeAggregateClassification::CLASSIFICATION_ROOT->value);
        $this->addSubtreeTagConstraints($queryBuilder);
        return $this->fetchNode($queryBuilder);
    }

    public function findParentNode(NodeAggregateId $childNodeAggregateId): ?Node
    {
        $parentNodeRecord = $this->findParentNodeRecord($childNodeAggregateId);

        return $parentNodeRecord
            ? $this->mapNodeRecordToNode($parentNodeRecord)
            : null;
    }

    public function findNodeByPath(NodePath|NodeName $path, NodeAggregateId $startingNodeAggregateId): ?Node
    {
        $path = $path instanceof NodeName ? NodePath::fromNodeNames($path) : $path;

        $startingNode = $this->findNodeById($startingNodeAggregateId);

        return $startingNode
            ? $this->findNodeByPathFromStartingNode($path, $startingNode)
            : null;
    }

    public function findNodeByAbsolutePath(AbsoluteNodePath $path): ?Node
    {
        $startingNode = $this->findRootNodeByType($path->rootNodeTypeName);

        return $startingNode
            ? $this->findNodeByPathFromStartingNode($path->path, $startingNode)
            : null;
    }

    /**
     * Find a single child node by its name
     *
     * @return Node|null the node that is connected to its parent with the specified $nodeName, or NULL if no matching node exists or the parent node is not accessible
     */
    private function findChildNodeConnectedThroughEdgeName(NodeAggregateId $parentNodeAggregateId, NodeName $nodeName): ?Node
    {
        foreach ($this->graphStructure->nodes[$this->contentStreamId->value][$parentNodeAggregateId->value] as $parentNodeRecord) {
            if ($parentNodeRecord->coversDimensionSpacePoint($this->contentStreamId, $this->dimensionSpacePoint)) {
                $childRelations = $parentNodeRecord->childrenByContentStream[$this->contentStreamId->value];
                foreach ($childRelations as $childRecords) {
                    if ($childRelations->getInfo() === $this->dimensionSpacePoint) {
                        foreach ($childRecords as $childRecord) {
                            if ($childRecord->name?->equals($nodeName)) {
                                return $this->mapNodeRecordToNode($childRecord);
                            }
                        }
                        break 2;
                    }
                }
            }
        }

        return null;
    }

    public function findSucceedingSiblingNodes(NodeAggregateId $siblingNodeAggregateId, FindSucceedingSiblingNodesFilter $filter): Nodes
    {
        /** @todo apply filters */
        $parentNodeRecord = $this->findParentNodeRecord($siblingNodeAggregateId);
        $siblingFound = false;
        $succeedingSiblingRecords = [];
        if ($parentNodeRecord !== null) {
            $childRelations = $parentNodeRecord->childrenByContentStream[$this->contentStreamId->value];
            foreach ($childRelations as $childRecords) {
                if ($childRelations->getInfo() === $this->dimensionSpacePoint) {
                    foreach ($childRecords as $childRecord) {
                        if ($childRecord->nodeAggregateId->equals($siblingNodeAggregateId)) {
                            $siblingFound = true;
                            continue;
                        }
                        if ($siblingFound) {
                            $succeedingSiblingRecords[] = $childRecord;
                        }
                    }
                }
            }
        } else {
            // this is a root node
            foreach ($this->graphStructure->rootNodes[$this->contentStreamId->value] as $rootNodeRecord) {
                if ($rootNodeRecord->coversDimensionSpacePoint($this->contentStreamId, $this->dimensionSpacePoint)) {
                    if ($rootNodeRecord->nodeAggregateId->equals($siblingNodeAggregateId)) {
                        $siblingFound = true;
                        continue;
                    }
                    if ($siblingFound) {
                        $succeedingSiblingRecords[] = $rootNodeRecord;
                    }
                }
            }
        }
        return $this->mapNodeRecordsToNodes($succeedingSiblingRecords);
    }

    public function findPrecedingSiblingNodes(NodeAggregateId $siblingNodeAggregateId, FindPrecedingSiblingNodesFilter $filter): Nodes
    {
        /** @todo apply filters */
        $parentNodeRecord = $this->findParentNodeRecord($siblingNodeAggregateId);
        $siblingFound = false;
        $precedingSiblingRecords = [];
        if ($parentNodeRecord !== null) {
            $childRelations = $parentNodeRecord->childrenByContentStream[$this->contentStreamId->value];
            foreach ($childRelations as $childRecords) {
                if ($childRelations->getInfo() === $this->dimensionSpacePoint) {
                    $childRecords = $childRecords->reverse();
                    foreach ($childRecords as $childRecord) {
                        if ($childRecord->nodeAggregateId->equals($siblingNodeAggregateId)) {
                            $siblingFound = true;
                            continue;
                        }
                        if ($siblingFound) {
                            $precedingSiblingRecords[] = $childRecord;
                        }
                    }
                }
            }
        } else {
            // this is a root node
            $rootNodeRecords = InMemoryNodeRecords::create(...$this->graphStructure->rootNodes[$this->contentStreamId->value]);
            $rootNodeRecords = $rootNodeRecords->reverse();
            foreach ($rootNodeRecords as $rootNodeRecord) {
                if ($rootNodeRecord->coversDimensionSpacePoint($this->contentStreamId, $this->dimensionSpacePoint)) {
                    if ($rootNodeRecord->nodeAggregateId->equals($siblingNodeAggregateId)) {
                        $siblingFound = true;
                        continue;
                    }
                    if ($siblingFound) {
                        $precedingSiblingRecords[] = $rootNodeRecord;
                    }
                }
            }
        }
        return $this->mapNodeRecordsToNodes($precedingSiblingRecords);
    }

    public function retrieveNodePath(NodeAggregateId $nodeAggregateId): AbsoluteNodePath
    {
        $leafNode = $this->findNodeById($nodeAggregateId);
        if (!$leafNode) {
            throw new \InvalidArgumentException(
                'Failed to retrieve node path for node "' . $nodeAggregateId->value . '"',
                1687513836
            );
        }
        $ancestors = $this->findAncestorNodes($leafNode->aggregateId, FindAncestorNodesFilter::create())
            ->reverse();

        try {
            return AbsoluteNodePath::fromLeafNodeAndAncestors($leafNode, $ancestors);
        } catch (\InvalidArgumentException $exception) {
            throw new \InvalidArgumentException(
                'Failed to retrieve node path for node "' . $nodeAggregateId->value . '"',
                1687513836,
                $exception
            );
        }
    }

    public function findSubtree(NodeAggregateId $entryNodeAggregateId, FindSubtreeFilter $filter): ?Subtree
    {
        $queryBuilderInitial = $this->createQueryBuilder()
            // @see https://mariadb.com/kb/en/library/recursive-common-table-expressions-overview/#cast-to-avoid-data-truncation
            ->select('n.*, h.subtreetags, CAST("ROOT" AS CHAR(50)) AS parentNodeAggregateId, 0 AS level, 0 AS position')
            ->from($this->nodeQueryBuilder->tableNames->node(), 'n')
            ->innerJoin('n', $this->nodeQueryBuilder->tableNames->hierarchyRelation(), 'h', 'h.childnodeanchor = n.relationanchorpoint')
            ->where('h.contentstreamid = :contentStreamId')
            ->andWhere('h.dimensionspacepointhash = :dimensionSpacePointHash')
            ->andWhere('n.nodeaggregateid = :entryNodeAggregateId');
        $this->addSubtreeTagConstraints($queryBuilderInitial);

        $queryBuilderRecursive = $this->createQueryBuilder()
            ->select('c.*, h.subtreetags, p.nodeaggregateid AS parentNodeAggregateId, p.level + 1 AS level, h.position')
            ->from('tree', 'p')
            ->innerJoin('p', $this->nodeQueryBuilder->tableNames->hierarchyRelation(), 'h', 'h.parentnodeanchor = p.relationanchorpoint')
            ->innerJoin('p', $this->nodeQueryBuilder->tableNames->node(), 'c', 'c.relationanchorpoint = h.childnodeanchor')
            ->where('h.contentstreamid = :contentStreamId')
            ->andWhere('h.dimensionspacepointhash = :dimensionSpacePointHash');
        if ($filter->maximumLevels !== null) {
            $queryBuilderRecursive->andWhere('p.level < :maximumLevels')->setParameter('maximumLevels', $filter->maximumLevels);
        }
        if ($filter->nodeTypes !== null) {
            $this->nodeQueryBuilder->addNodeTypeCriteria($queryBuilderRecursive, ExpandedNodeTypeCriteria::create($filter->nodeTypes, $this->nodeTypeManager), 'c');
        }
        $this->addSubtreeTagConstraints($queryBuilderRecursive);

        $queryBuilderCte = $this->createQueryBuilder()
            ->select('*')
            ->from('tree')
            ->orderBy('level')
            ->addOrderBy('position')
            ->setParameter('contentStreamId', $this->contentStreamId->value)
            ->setParameter('dimensionSpacePointHash', $this->dimensionSpacePoint->hash)
            ->setParameter('entryNodeAggregateId', $entryNodeAggregateId->value);

        $result = $this->fetchCteResults($queryBuilderInitial, $queryBuilderRecursive, $queryBuilderCte, 'tree');
        /** @var array<string, Subtree[]> $subtreesByParentNodeId */
        $subtreesByParentNodeId = [];
        foreach (array_reverse($result) as $nodeData) {
            $nodeAggregateId = $nodeData['nodeaggregateid'];
            $parentNodeAggregateId = $nodeData['parentNodeAggregateId'];
            $node = $this->nodeFactory->mapNodeRecordToNode(
                $nodeData,
                $this->workspaceName,
                $this->dimensionSpacePoint,
                $this->visibilityConstraints
            );
            $subtree = Subtree::create(
                (int)$nodeData['level'],
                $node,
                array_key_exists($nodeAggregateId, $subtreesByParentNodeId) ? Subtrees::fromArray(array_reverse($subtreesByParentNodeId[$nodeAggregateId])) : Subtrees::createEmpty()
            );
            if ($subtree->level === 0) {
                return $subtree;
            }
            if (!array_key_exists($parentNodeAggregateId, $subtreesByParentNodeId)) {
                $subtreesByParentNodeId[$parentNodeAggregateId] = [];
            }
            $subtreesByParentNodeId[$parentNodeAggregateId][] = $subtree;
        }
        return null;
    }

    public function findAncestorNodes(NodeAggregateId $entryNodeAggregateId, FindAncestorNodesFilter $filter): Nodes
    {
        [
            'queryBuilderInitial' => $queryBuilderInitial,
            'queryBuilderRecursive' => $queryBuilderRecursive,
            'queryBuilderCte' => $queryBuilderCte
        ] = $this->buildAncestorNodesQueries($entryNodeAggregateId, $filter);
        $queryBuilderCte->addOrderBy('level');

        $nodeRows = $this->fetchCteResults(
            $queryBuilderInitial,
            $queryBuilderRecursive,
            $queryBuilderCte,
            'ancestry'
        );

        return $this->nodeFactory->mapNodeRecordsToNodes(
            $nodeRows,
            $this->workspaceName,
            $this->dimensionSpacePoint,
            $this->visibilityConstraints
        );
    }

    public function countAncestorNodes(NodeAggregateId $entryNodeAggregateId, CountAncestorNodesFilter $filter): int
    {
        [
            'queryBuilderInitial' => $queryBuilderInitial,
            'queryBuilderRecursive' => $queryBuilderRecursive,
            'queryBuilderCte' => $queryBuilderCte
        ] = $this->buildAncestorNodesQueries($entryNodeAggregateId, $filter);

        return $this->fetchCteCountResult(
            $queryBuilderInitial,
            $queryBuilderRecursive,
            $queryBuilderCte,
            'ancestry'
        );
    }

    public function findClosestNode(NodeAggregateId $entryNodeAggregateId, FindClosestNodeFilter $filter): ?Node
    {
        $queryBuilderInitial = $this->createQueryBuilder()
            ->select('n.*, ph.subtreetags, ph.parentnodeanchor')
            ->from($this->nodeQueryBuilder->tableNames->node(), 'n')
            // we need to join with the hierarchy relation, because we need the node name.
            ->innerJoin('n', $this->nodeQueryBuilder->tableNames->hierarchyRelation(), 'ph', 'n.relationanchorpoint = ph.childnodeanchor')
            ->andWhere('ph.contentstreamid = :contentStreamId')
            ->andWhere('ph.dimensionspacepointhash = :dimensionSpacePointHash')
            ->andWhere('n.nodeaggregateid = :entryNodeAggregateId');
        $this->addSubtreeTagConstraints($queryBuilderInitial, 'ph');

        $queryBuilderRecursive = $this->createQueryBuilder()
            ->select('pn.*, h.subtreetags, h.parentnodeanchor')
            ->from('ancestry', 'cn')
            ->innerJoin('cn', $this->nodeQueryBuilder->tableNames->node(), 'pn', 'pn.relationanchorpoint = cn.parentnodeanchor')
            ->innerJoin('pn', $this->nodeQueryBuilder->tableNames->hierarchyRelation(), 'h', 'h.childnodeanchor = pn.relationanchorpoint')
            ->where('h.contentstreamid = :contentStreamId')
            ->andWhere('h.dimensionspacepointhash = :dimensionSpacePointHash');
        $this->addSubtreeTagConstraints($queryBuilderRecursive);

        $queryBuilderCte = $this->nodeQueryBuilder->buildBasicNodesCteQuery($entryNodeAggregateId, $this->contentStreamId, $this->dimensionSpacePoint);
        $this->nodeQueryBuilder->addNodeTypeCriteria($queryBuilderCte, ExpandedNodeTypeCriteria::create($filter->nodeTypes, $this->nodeTypeManager), 'pn');
        $nodeRows = $this->fetchCteResults(
            $queryBuilderInitial,
            $queryBuilderRecursive,
            $queryBuilderCte,
            'ancestry'
        );
        return $this->nodeFactory->mapNodeRecordsToNodes(
            $nodeRows,
            $this->workspaceName,
            $this->dimensionSpacePoint,
            $this->visibilityConstraints
        )->first();
    }

    public function findDescendantNodes(NodeAggregateId $entryNodeAggregateId, FindDescendantNodesFilter $filter): Nodes
    {
        ['queryBuilderInitial' => $queryBuilderInitial, 'queryBuilderRecursive' => $queryBuilderRecursive, 'queryBuilderCte' => $queryBuilderCte] = $this->buildDescendantNodesQueries($entryNodeAggregateId, $filter);
        if ($filter->ordering !== null) {
            $this->applyOrdering($queryBuilderCte, $filter->ordering);
        }
        if ($filter->pagination !== null) {
            $this->applyPagination($queryBuilderCte, $filter->pagination);
        }
        $queryBuilderCte->addOrderBy('level')->addOrderBy('position');
        $nodeRows = $this->fetchCteResults($queryBuilderInitial, $queryBuilderRecursive, $queryBuilderCte, 'tree');
        return $this->nodeFactory->mapNodeRecordsToNodes(
            $nodeRows,
            $this->workspaceName,
            $this->dimensionSpacePoint,
            $this->visibilityConstraints
        );
    }

    public function countDescendantNodes(NodeAggregateId $entryNodeAggregateId, CountDescendantNodesFilter $filter): int
    {
        ['queryBuilderInitial' => $queryBuilderInitial, 'queryBuilderRecursive' => $queryBuilderRecursive, 'queryBuilderCte' => $queryBuilderCte] = $this->buildDescendantNodesQueries($entryNodeAggregateId, $filter);
        return $this->fetchCteCountResult($queryBuilderInitial, $queryBuilderRecursive, $queryBuilderCte, 'tree');
    }

    public function countNodes(): int
    {
        $numberOfNodes = 0;
        foreach ($this->graphStructure->nodes[$this->contentStreamId->value] as $nodeRecords) {
            foreach ($nodeRecords as $nodeRecord) {
                if (
                    $nodeRecord->parentsByContentStreamId[$this->contentStreamId->value]
                        ->getCoveredDimensionSpacePointSet()
                        ->contains($this->dimensionSpacePoint)
                ) {
                    $numberOfNodes++;
                }
            }
        }

        return $numberOfNodes;
    }

    /** ------------------------------------------- */

    private function findNodeByPathFromStartingNode(NodePath $path, Node $startingNode): ?Node
    {
        $currentNode = $startingNode;

        foreach ($path->getParts() as $edgeName) {
            $currentNode = $this->findChildNodeConnectedThroughEdgeName($currentNode->aggregateId, $edgeName);
            if ($currentNode === null) {
                return null;
            }
        }
        return $currentNode;
    }

    private function createQueryBuilder(): QueryBuilder
    {
        return $this->dbal->createQueryBuilder();
    }

    private function addSubtreeTagConstraints(QueryBuilder $queryBuilder, string $hierarchyRelationTableAlias = 'h'): void
    {
        $hierarchyRelationTablePrefix = $hierarchyRelationTableAlias === '' ? '' : $hierarchyRelationTableAlias . '.';
        $i = 0;
        foreach ($this->visibilityConstraints->excludedSubtreeTags as $excludedTag) {
            $queryBuilder->andWhere('NOT JSON_CONTAINS_PATH(' . $hierarchyRelationTablePrefix . 'subtreetags, \'one\', :tagPath' . $i . ')')->setParameter('tagPath' . $i, '$."' . $excludedTag->value . '"');
            $i++;
        }
    }

    private function buildChildNodesQuery(NodeAggregateId $parentNodeAggregateId, FindChildNodesFilter|CountChildNodesFilter $filter): QueryBuilder
    {
        $queryBuilder = $this->nodeQueryBuilder->buildBasicChildNodesQuery($parentNodeAggregateId, $this->contentStreamId, $this->dimensionSpacePoint);
        if ($filter->nodeTypes !== null) {
            $this->nodeQueryBuilder->addNodeTypeCriteria($queryBuilder, ExpandedNodeTypeCriteria::create($filter->nodeTypes, $this->nodeTypeManager));
        }
        if ($filter->searchTerm !== null) {
            $this->nodeQueryBuilder->addSearchTermConstraints($queryBuilder, $filter->searchTerm);
        }
        if ($filter->propertyValue !== null) {
            $this->nodeQueryBuilder->addPropertyValueConstraints($queryBuilder, $filter->propertyValue);
        }
        $this->addSubtreeTagConstraints($queryBuilder);
        return $queryBuilder;
    }

    private function buildReferencesQuery(bool $backReferences, NodeAggregateId $nodeAggregateId, FindReferencesFilter|FindBackReferencesFilter|CountReferencesFilter|CountBackReferencesFilter $filter): QueryBuilder
    {
        $sourceTablePrefix = $backReferences ? 'd' : 's';
        $destinationTablePrefix = $backReferences ? 's' : 'd';
        $queryBuilder = $this->createQueryBuilder()
            ->select("{$destinationTablePrefix}n.*, {$destinationTablePrefix}h.subtreetags, r.name AS referencename, r.properties AS referenceproperties")
            ->from($this->nodeQueryBuilder->tableNames->hierarchyRelation(), 'sh')
            ->innerJoin('sh', $this->nodeQueryBuilder->tableNames->node(), 'sn', 'sn.relationanchorpoint = sh.childnodeanchor')
            ->innerJoin('sh', $this->nodeQueryBuilder->tableNames->referenceRelation(), 'r', 'r.nodeanchorpoint = sn.relationanchorpoint')
            ->innerJoin('sh', $this->nodeQueryBuilder->tableNames->node(), 'dn', 'dn.nodeaggregateid = r.destinationnodeaggregateid')
            ->innerJoin('sh', $this->nodeQueryBuilder->tableNames->hierarchyRelation(), 'dh', 'dh.childnodeanchor = dn.relationanchorpoint')
            ->where("{$sourceTablePrefix}n.nodeaggregateid = :nodeAggregateId")->setParameter('nodeAggregateId', $nodeAggregateId->value)
            ->andWhere('dh.dimensionspacepointhash = :dimensionSpacePointHash')->setParameter('dimensionSpacePointHash', $this->dimensionSpacePoint->hash)
            ->andWhere('sh.dimensionspacepointhash = :dimensionSpacePointHash')
            ->andWhere('dh.contentstreamid = :contentStreamId')->setParameter('contentStreamId', $this->contentStreamId->value)
            ->andWhere('sh.contentstreamid = :contentStreamId');
        $this->addSubtreeTagConstraints($queryBuilder, 'dh');
        $this->addSubtreeTagConstraints($queryBuilder, 'sh');
        if ($filter->nodeTypes !== null) {
            $this->nodeQueryBuilder->addNodeTypeCriteria($queryBuilder, ExpandedNodeTypeCriteria::create($filter->nodeTypes, $this->nodeTypeManager), "{$destinationTablePrefix}n");
        }
        if ($filter->nodeSearchTerm !== null) {
            $this->nodeQueryBuilder->addSearchTermConstraints($queryBuilder, $filter->nodeSearchTerm, "{$destinationTablePrefix}n");
        }
        if ($filter->nodePropertyValue !== null) {
            $this->nodeQueryBuilder->addPropertyValueConstraints($queryBuilder, $filter->nodePropertyValue, "{$destinationTablePrefix}n");
        }
        if ($filter->referenceSearchTerm !== null) {
            $this->nodeQueryBuilder->addSearchTermConstraints($queryBuilder, $filter->referenceSearchTerm, 'r');
        }
        if ($filter->referencePropertyValue !== null) {
            $this->nodeQueryBuilder->addPropertyValueConstraints($queryBuilder, $filter->referencePropertyValue, 'r');
        }
        if ($filter->referenceName !== null) {
            $queryBuilder->andWhere('r.name = :referenceName')->setParameter('referenceName', $filter->referenceName->value);
        }
        if ($filter instanceof FindReferencesFilter || $filter instanceof FindBackReferencesFilter) {
            if ($filter->ordering !== null) {
                $this->applyOrdering($queryBuilder, $filter->ordering, "{$destinationTablePrefix}n");
            } elseif ($filter->referenceName === null) {
                $queryBuilder->addOrderBy('r.name');
            }
            $queryBuilder->addOrderBy('r.position');
            $queryBuilder->addOrderBy('sn.nodeaggregateid');
            if ($filter->pagination !== null) {
                $this->applyPagination($queryBuilder, $filter->pagination);
            }
        }
        return $queryBuilder;
    }

    private function buildSiblingsQuery(bool $preceding, NodeAggregateId $siblingNodeAggregateId, FindPrecedingSiblingNodesFilter|FindSucceedingSiblingNodesFilter $filter): QueryBuilder
    {
        $queryBuilder = $this->nodeQueryBuilder->buildBasicNodeSiblingsQuery($preceding, $siblingNodeAggregateId, $this->contentStreamId, $this->dimensionSpacePoint);

        $this->addSubtreeTagConstraints($queryBuilder);
        if ($filter->nodeTypes !== null) {
            $this->nodeQueryBuilder->addNodeTypeCriteria($queryBuilder, ExpandedNodeTypeCriteria::create($filter->nodeTypes, $this->nodeTypeManager));
        }
        if ($filter->searchTerm !== null) {
            $this->nodeQueryBuilder->addSearchTermConstraints($queryBuilder, $filter->searchTerm);
        }
        if ($filter->propertyValue !== null) {
            $this->nodeQueryBuilder->addPropertyValueConstraints($queryBuilder, $filter->propertyValue);
        }
        if ($filter->pagination !== null) {
            $this->applyPagination($queryBuilder, $filter->pagination);
        }
        return $queryBuilder;
    }

    /**
     * @return array{queryBuilderInitial: QueryBuilder, queryBuilderRecursive: QueryBuilder, queryBuilderCte: QueryBuilder}
     */
    private function buildAncestorNodesQueries(NodeAggregateId $entryNodeAggregateId, FindAncestorNodesFilter|CountAncestorNodesFilter|FindClosestNodeFilter $filter): array
    {
        $queryBuilderInitial = $this->createQueryBuilder()
            ->select('n.*, ph.subtreetags, ph.parentnodeanchor, 0 AS level')
            ->from($this->nodeQueryBuilder->tableNames->node(), 'n')
            // we need to join with the hierarchy relation, because we need the node name.
            ->innerJoin('n', $this->nodeQueryBuilder->tableNames->hierarchyRelation(), 'ch', 'ch.parentnodeanchor = n.relationanchorpoint')
            ->innerJoin('ch', $this->nodeQueryBuilder->tableNames->node(), 'c', 'c.relationanchorpoint = ch.childnodeanchor')
            ->innerJoin('n', $this->nodeQueryBuilder->tableNames->hierarchyRelation(), 'ph', 'n.relationanchorpoint = ph.childnodeanchor')
            ->where('ch.contentstreamid = :contentStreamId')
            ->andWhere('ch.dimensionspacepointhash = :dimensionSpacePointHash')
            ->andWhere('ph.contentstreamid = :contentStreamId')
            ->andWhere('ph.dimensionspacepointhash = :dimensionSpacePointHash')
            ->andWhere('c.nodeaggregateid = :entryNodeAggregateId');
        $this->addSubtreeTagConstraints($queryBuilderInitial, 'ph');
        $this->addSubtreeTagConstraints($queryBuilderInitial, 'ch');

        $queryBuilderRecursive = $this->createQueryBuilder()
            ->select('pn.*, h.subtreetags, h.parentnodeanchor,  ch.level + 1 AS level')
            ->from('ancestry', 'ch')
            ->innerJoin('ch', $this->nodeQueryBuilder->tableNames->node(), 'pn', 'pn.relationanchorpoint = ch.parentnodeanchor')
            ->innerJoin('pn', $this->nodeQueryBuilder->tableNames->hierarchyRelation(), 'h', 'h.childnodeanchor = pn.relationanchorpoint')
            ->where('h.contentstreamid = :contentStreamId')
            ->andWhere('h.dimensionspacepointhash = :dimensionSpacePointHash');
        $this->addSubtreeTagConstraints($queryBuilderRecursive);

        $queryBuilderCte = $this->nodeQueryBuilder->buildBasicNodesCteQuery($entryNodeAggregateId, $this->contentStreamId, $this->dimensionSpacePoint);
        if ($filter->nodeTypes !== null) {
            $this->nodeQueryBuilder->addNodeTypeCriteria($queryBuilderCte, ExpandedNodeTypeCriteria::create($filter->nodeTypes, $this->nodeTypeManager), 'pn');
        }
        return compact('queryBuilderInitial', 'queryBuilderRecursive', 'queryBuilderCte');
    }

    /**
     * @return array{queryBuilderInitial: QueryBuilder, queryBuilderRecursive: QueryBuilder, queryBuilderCte: QueryBuilder}
     */
    private function buildDescendantNodesQueries(NodeAggregateId $entryNodeAggregateId, FindDescendantNodesFilter|CountDescendantNodesFilter $filter): array
    {
        $queryBuilderInitial = $this->createQueryBuilder()
            // @see https://mariadb.com/kb/en/library/recursive-common-table-expressions-overview/#cast-to-avoid-data-truncation
            ->select('n.*, h.subtreetags, CAST("ROOT" AS CHAR(50)) AS parentNodeAggregateId, 0 AS level, 0 AS position')
            ->from($this->nodeQueryBuilder->tableNames->node(), 'n')
            // we need to join with the hierarchy relation, because we need the node name.
            ->innerJoin('n', $this->nodeQueryBuilder->tableNames->hierarchyRelation(), 'h', 'h.childnodeanchor = n.relationanchorpoint')
            ->innerJoin('n', $this->nodeQueryBuilder->tableNames->node(), 'p', 'p.relationanchorpoint = h.parentnodeanchor')
            ->innerJoin('n', $this->nodeQueryBuilder->tableNames->hierarchyRelation(), 'ph', 'ph.childnodeanchor = p.relationanchorpoint')
            ->where('h.contentstreamid = :contentStreamId')
            ->andWhere('h.dimensionspacepointhash = :dimensionSpacePointHash')
            ->andWhere('ph.contentstreamid = :contentStreamId')
            ->andWhere('ph.dimensionspacepointhash = :dimensionSpacePointHash')
            ->andWhere('p.nodeaggregateid = :entryNodeAggregateId');
        $this->addSubtreeTagConstraints($queryBuilderInitial);

        $queryBuilderRecursive = $this->createQueryBuilder()
            ->select('cn.*, h.subtreetags, pn.nodeaggregateid AS parentNodeAggregateId, pn.level + 1 AS level, h.position')
            ->from('tree', 'pn')
            ->innerJoin('pn', $this->nodeQueryBuilder->tableNames->hierarchyRelation(), 'h', 'h.parentnodeanchor = pn.relationanchorpoint')
            ->innerJoin('pn', $this->nodeQueryBuilder->tableNames->node(), 'cn', 'cn.relationanchorpoint = h.childnodeanchor')
            ->where('h.contentstreamid = :contentStreamId')
            ->andWhere('h.dimensionspacepointhash = :dimensionSpacePointHash');
        $this->addSubtreeTagConstraints($queryBuilderRecursive);

        $queryBuilderCte = $this->nodeQueryBuilder->buildBasicNodesCteQuery($entryNodeAggregateId, $this->contentStreamId, $this->dimensionSpacePoint, 'tree', 'n');
        if ($filter->nodeTypes !== null) {
            $this->nodeQueryBuilder->addNodeTypeCriteria($queryBuilderCte, ExpandedNodeTypeCriteria::create($filter->nodeTypes, $this->nodeTypeManager));
        }
        if ($filter->searchTerm !== null) {
            $this->nodeQueryBuilder->addSearchTermConstraints($queryBuilderCte, $filter->searchTerm);
        }
        if ($filter->propertyValue !== null) {
            $this->nodeQueryBuilder->addPropertyValueConstraints($queryBuilderCte, $filter->propertyValue);
        }
        return compact('queryBuilderInitial', 'queryBuilderRecursive', 'queryBuilderCte');
    }

    private function applyOrdering(QueryBuilder $queryBuilder, Ordering $ordering, string $nodeTableAlias = 'n'): void
    {
        foreach ($ordering as $orderingField) {
            $order = match ($orderingField->direction) {
                OrderingDirection::ASCENDING => 'ASC',
                OrderingDirection::DESCENDING => 'DESC',
            };
            if ($orderingField->field instanceof PropertyName) {
                $queryBuilder->addOrderBy($this->nodeQueryBuilder->extractPropertyValue($orderingField->field, $nodeTableAlias), $order);
            } else {
                $timestampColumnName = match ($orderingField->field) {
                    TimestampField::CREATED => 'created',
                    TimestampField::ORIGINAL_CREATED => 'originalCreated',
                    TimestampField::LAST_MODIFIED => 'lastmodified',
                    TimestampField::ORIGINAL_LAST_MODIFIED => 'originallastmodified',
                };
                $queryBuilder->addOrderBy($nodeTableAlias . '.' . $timestampColumnName, $order);
            }
        }
    }

    private function applyPagination(QueryBuilder $queryBuilder, Pagination $pagination): void
    {
        $queryBuilder->setMaxResults($pagination->limit)->setFirstResult($pagination->offset);
    }

    /**
     * @param QueryBuilder $queryBuilder
     * @return Result
     * @throws DBALException
     */
    private function executeQuery(QueryBuilder $queryBuilder): Result
    {
        return $queryBuilder->executeQuery();
    }

    private function fetchNode(QueryBuilder $queryBuilder): ?Node
    {
        try {
            $nodeRow = $this->executeQuery($queryBuilder)->fetchAssociative();
        } catch (DBALException $e) {
            throw new \RuntimeException(sprintf('Failed to fetch node: %s', $e->getMessage()), 1678286030, $e);
        }
        if ($nodeRow === false) {
            return null;
        }
        return $this->nodeFactory->mapNodeRecordToNode(
            $nodeRow,
            $this->workspaceName,
            $this->dimensionSpacePoint,
            $this->visibilityConstraints
        );
    }

    private function fetchNodes(QueryBuilder $queryBuilder): Nodes
    {
        try {
            $nodeRows = $this->executeQuery($queryBuilder)->fetchAllAssociative();
        } catch (DBALException $e) {
            throw new \RuntimeException(sprintf('Failed to fetch nodes: %s', $e->getMessage()), 1678292896, $e);
        }
        return $this->nodeFactory->mapNodeRecordsToNodes(
            $nodeRows,
            $this->workspaceName,
            $this->dimensionSpacePoint,
            $this->visibilityConstraints
        );
    }

    private function fetchCount(QueryBuilder $queryBuilder): int
    {
        try {
            return (int)$this->executeQuery($queryBuilder->select('COUNT(*)')->resetOrderBy()->setFirstResult(0)->setMaxResults(1))->fetchOne();
        } catch (DBALException $e) {
            throw new \RuntimeException(sprintf('Failed to fetch count: %s', $e->getMessage()), 1679048349, $e);
        }
    }

    private function fetchReferences(QueryBuilder $queryBuilder): References
    {
        try {
            $referenceRows = $this->executeQuery($queryBuilder)->fetchAllAssociative();
        } catch (DBALException $e) {
            throw new \RuntimeException(sprintf('Failed to fetch references: %s', $e->getMessage()), 1678364944, $e);
        }
        return $this->nodeFactory->mapReferenceRecordsToReferences(
            $referenceRows,
            $this->workspaceName,
            $this->dimensionSpacePoint,
            $this->visibilityConstraints
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchCteResults(QueryBuilder $queryBuilderInitial, QueryBuilder $queryBuilderRecursive, QueryBuilder $queryBuilderCte, string $cteTableName = 'cte'): array
    {
        $query = <<<SQL
            WITH RECURSIVE {$cteTableName} AS (
                {$queryBuilderInitial->getSQL()}
                UNION
                {$queryBuilderRecursive->getSQL()}
            )
            {$queryBuilderCte->getSQL()}
        SQL;
        $parameters = array_merge($queryBuilderInitial->getParameters(), $queryBuilderRecursive->getParameters(), $queryBuilderCte->getParameters());
        $parameterTypes = array_merge($queryBuilderInitial->getParameterTypes(), $queryBuilderRecursive->getParameterTypes(), $queryBuilderCte->getParameterTypes());
        try {
            return $this->dbal->fetchAllAssociative($query, $parameters, $parameterTypes);
        } catch (DBALException $e) {
            throw new \RuntimeException(sprintf('Failed to fetch CTE result: %s', $e->getMessage()), 1678358108, $e);
        }
    }

    private function fetchCteCountResult(QueryBuilder $queryBuilderInitial, QueryBuilder $queryBuilderRecursive, QueryBuilder $queryBuilderCte, string $cteTableName = 'cte'): int
    {
        $query = <<<SQL
            WITH RECURSIVE {$cteTableName} AS (
                {$queryBuilderInitial->getSQL()}
                UNION
                {$queryBuilderRecursive->getSQL()}
            )
            {$queryBuilderCte->select('COUNT(*)')->resetOrderBy()->setFirstResult(0)->setMaxResults(1)}
        SQL;
        $parameters = array_merge($queryBuilderInitial->getParameters(), $queryBuilderRecursive->getParameters(), $queryBuilderCte->getParameters());
        $parameterTypes = array_merge($queryBuilderInitial->getParameterTypes(), $queryBuilderRecursive->getParameterTypes(), $queryBuilderCte->getParameterTypes());
        try {
            return (int)$this->dbal->fetchOne($query, $parameters, $parameterTypes);
        } catch (DBALException $e) {
            throw new \RuntimeException(sprintf('Failed to fetch CTE count result: %s', $e->getMessage()), 1679047841, $e);
        }
    }

    private function findParentNodeRecord(NodeAggregateId $childNodeAggregateId): ?InMemoryNodeRecord
    {
        $nodeRecords = $this->graphStructure->nodes[$this->contentStreamId->value][$childNodeAggregateId->value];
        foreach ($nodeRecords as $nodeRecord) {
            foreach ($nodeRecord->parentsByContentStreamId[$this->contentStreamId->value] as $parentNodeRecord) {
                if (
                    $parentNodeRecord instanceof InMemoryNodeRecord
                    && $nodeRecord->coversDimensionSpacePoint($this->contentStreamId, $this->dimensionSpacePoint)
                ) {
                    return $parentNodeRecord;
                }
            }
        }

        return null;
    }

    private function mapNodeRecordToNode(InMemoryNodeRecord $nodeRecord): Node
    {
        return $this->nodeFactory->mapNodeRecordToNode(
            $nodeRecord,
            $this->workspaceName,
            $this->dimensionSpacePoint,
            $this->visibilityConstraints,
        );
    }

    /**
     * @param iterable<int, InMemoryNodeRecord> $nodeRecords
     */
    private function mapNodeRecordsToNodes(iterable $nodeRecords): Nodes
    {
        return $this->nodeFactory->mapNodeRecordsToNodes(
            $nodeRecords,
            $this->workspaceName,
            $this->dimensionSpacePoint,
            $this->visibilityConstraints,
        );
    }

    /**
     * @param array<int,InMemoryReferenceRecord> $referenceRecords
     */
    private function mapReferenceRecordsToReferences(
        array $referenceRecords,
        bool $backwards,
    ): References {
        return $this->nodeFactory->mapReferenceRecordsToReferences(
            $referenceRecords,
            $this->workspaceName,
            $this->dimensionSpacePoint,
            $this->visibilityConstraints,
            $backwards,
        );
    }
}
