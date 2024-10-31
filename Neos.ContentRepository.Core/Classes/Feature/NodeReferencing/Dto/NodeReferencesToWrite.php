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

namespace Neos\ContentRepository\Core\Feature\NodeReferencing\Dto;

/**
 * Node references to write, supports arbitrary objects as reference values.
 * Will be then converted to {@see SerializedNodeReferences} inside the events and persisted commands.
 *
 * We expect the value types to match the NodeType's property types (this is validated in the command handler).
 *
 * @implements \IteratorAggregate<NodeReferencesForName>
 * @api used as part of commands
 */
final readonly class NodeReferencesToWrite implements \IteratorAggregate
{
    /**
     * @var array<string, NodeReferencesForName>
     */
    public array $references;

    private function __construct(NodeReferencesForName ...$references)
    {
        $referencesByName = [];
        foreach ($references as $reference) {
            if (isset($referencesByName[$reference->referenceName->value])) {
                throw new \InvalidArgumentException(sprintf('NodeReferencesToWrite does not accept references for the same name %s multiple times.', $reference->referenceName->value), 1718193720);
            }
            $referencesByName[$reference->referenceName->value] = $reference;
        }
        $this->references = $referencesByName;
    }

    public static function createEmpty(): self
    {
        return new self();
    }

    public static function fromReferences(NodeReferencesForName ...$references): self
    {
        return new self(...$references);
    }

    /**
     * @param array<NodeReferencesForName> $references
     */
    public static function fromArray(array $references): self
    {
        return new self(...$references);
    }

    public function withReference(NodeReferencesForName $referencesForName): self
    {
        $references = $this->references;
        $references[$referencesForName->referenceName->value] = $referencesForName;
        return new self(...$references);
    }

    public function merge(NodeReferencesToWrite $other): self
    {
        return new self(...array_merge($this->references, $other->references));
    }

    public function getIterator(): \Traversable
    {
        yield from array_values($this->references);
    }

    public function isEmpty(): bool
    {
        return count($this->references) === 0;
    }
}
