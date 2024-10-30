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

use Neos\ContentRepository\Core\SharedModel\Node\ReferenceName;

/**
 * @api used as part of commands
 */
final readonly class NodeReferencesForName
{
    /**
     * @var array<NodeReferenceToWrite>
     */
    public array $references;

    private function __construct(
        public ReferenceName $referenceName,
        NodeReferenceToWrite ...$references
    ) {
        $this->references = $references;
    }

    /**
     * @param NodeReferenceToWrite[] $references
     */
    public static function fromNameAndReferences(ReferenceName $name, array $references): self
    {
        return new self($name, ...$references);
    }

    public static function emptyForName(ReferenceName $name): self
    {
        return new self($name, ...[]);
    }
}
