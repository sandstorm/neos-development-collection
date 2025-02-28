<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Projection\ContentGraph;

use JsonSerializable;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePointSet;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Dto\SubtreeTag;

/**
 * DTO that holds the dimension space points any subtree tag is explicitly set for in a {@see NodeAggregate}
 *
 * We need to pass this explicit to the NodeAggregate as this information doesn't exist otherwise.
 *
 * Because $coveredDimensionSpacePoints only points to the occupying nodes there are no entries for specialisations,
 * and we CANNOT replace {@see NodeAggregate::getDimensionSpacePointsTaggedWith()} by iterating over these as explicitly
 * disabled specialisations will not show up as tagged:
 *
 *     foreach ($this->coveredDimensionSpacePoints as $dimensionSpacePoint) {
 *         $node = $this->getNodeByCoveredDimensionSpacePoint($dimensionSpacePoint);
 *         if ($node->tags->withoutInherited()->contain($subtreeTag)) {
 *             $taggedDimensions[] = $dimensionSpacePoint;
 *         }
 *     }
 *
 * We could replace this object if we also add these specialisation node rows explicitly to the NodeAggregate.
 *
 * @internal used by {@see NodeAggregate} but this is a low level concept that should not be relied upon outside the core and in tests
 */
final readonly class DimensionSpacePointsBySubtreeTags implements JsonSerializable
{
    /**
     * @param array<string,DimensionSpacePointSet> $dimensionSpacePointsBySubtreeTags
     */
    public function __construct(
        private array $dimensionSpacePointsBySubtreeTags,
    ) {
    }

    public static function create(): self
    {
        return new self([]);
    }

    public function withSubtreeTagAndDimensionSpacePoint(SubtreeTag $subtreeTag, DimensionSpacePoint $dimensionSpacePoint): self
    {
        $dimensionSpacePointsBySubtreeTags = $this->dimensionSpacePointsBySubtreeTags;
        if (!array_key_exists($subtreeTag->value, $dimensionSpacePointsBySubtreeTags)) {
            $dimensionSpacePointsBySubtreeTags[$subtreeTag->value] = DimensionSpacePointSet::fromArray([]);
        }
        if ($dimensionSpacePointsBySubtreeTags[$subtreeTag->value]->contains($dimensionSpacePoint)) {
            return $this;
        }
        $dimensionSpacePointsBySubtreeTags[$subtreeTag->value] = $dimensionSpacePointsBySubtreeTags[$subtreeTag->value]->getUnion(DimensionSpacePointSet::fromArray([$dimensionSpacePoint]));
        return new self($dimensionSpacePointsBySubtreeTags);
    }

    /**
     * Returns the dimension space points the specified $subtreeTag is _explicitly_ set in, or an empty set if none of the variants are tagged with $subtreeTag
     */
    public function forSubtreeTag(SubtreeTag $subtreeTag): DimensionSpacePointSet
    {
        return $this->dimensionSpacePointsBySubtreeTags[$subtreeTag->value] ?? DimensionSpacePointSet::fromArray([]);
    }

    /**
     * @return array<string,DimensionSpacePointSet>
     */
    public function jsonSerialize(): array
    {
        return $this->dimensionSpacePointsBySubtreeTags;
    }
}
