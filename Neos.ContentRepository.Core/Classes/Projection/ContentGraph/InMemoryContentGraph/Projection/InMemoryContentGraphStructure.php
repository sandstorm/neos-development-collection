<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Projection\ContentGraph\InMemoryContentGraph\Projection;

/**
 * The actual in-memory graph structure, connecting @see InMemoryNodeRecord objects
 *
 * @internal
 */
final class InMemoryContentGraphStructure
{
    /**
     * @param array<string,array<string,array<string,InMemoryNodeRecord>>> $nodes
     * indexed by content stream ID, node aggregate ID and origin dimension space point hash
     * @param array<string,array<string,InMemoryNodeRecord>> $rootNodes
     * indexed by content stream ID and (root) node type name
     * @param array<string,InMemoryReferenceHyperrelation> $references
     * indexed by content stream ID
     */
    public function __construct(
        public array $nodes = [],
        public array $rootNodes = [],
        public array $references = [],
        public int $totalNodeCount = 0,
    ) {
    }

    public function reset(): void
    {
        $this->nodes = [];
        $this->rootNodes = [];
        $this->references = [];
        $this->totalNodeCount = 0;
    }
}
