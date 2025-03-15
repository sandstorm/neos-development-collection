<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Feature\DimensionSpaceAdjustment\Command;

/*
 * This file is part of the Neos.ContentRepository package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\Common\RebasableToOtherWorkspaceInterface;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;

/**
 * Move a dimension space point to a new location; basically moving all content to the new dimension space point.
 *
 * This is used to *rename* dimension space points, e.g. from "de" to "de_DE".
 *
 * NOTE: the target dimension space point must not contain any content.
 *
 * @api commands are the write-API of the ContentRepository
 */
final readonly class MoveDimensionSpacePoint implements
    \JsonSerializable,
    CommandInterface,
    RebasableToOtherWorkspaceInterface
{
    /**
     * @param WorkspaceName $workspaceName The name of the workspace to perform the operation in.
     * @param DimensionSpacePoint $source source dimension space point
     * @param DimensionSpacePoint $target target dimension space point
     * @param ?WorkspaceName $baseWorkspaceName An optional base workspace name that will be ignored during change validation
     */
    private function __construct(
        public WorkspaceName $workspaceName,
        public DimensionSpacePoint $source,
        public DimensionSpacePoint $target,
        public ?WorkspaceName $baseWorkspaceName = null,
    ) {
    }

    /**
     * @param WorkspaceName $workspaceName The name of the workspace to perform the operation in
     * @param DimensionSpacePoint $source source dimension space point
     * @param DimensionSpacePoint $target target dimension space point
     */
    public static function create(
        WorkspaceName $workspaceName,
        DimensionSpacePoint $source,
        DimensionSpacePoint $target
    ): self {
        return new self($workspaceName, $source, $target);
    }

    public function withBaseWorkspaceName(WorkspaceName $baseWorkspaceName): self
    {
        return new self(
            $this->workspaceName,
            $this->source,
            $this->target,
            $baseWorkspaceName
        );
    }

    public static function fromArray(array $array): self
    {
        return new self(
            WorkspaceName::fromString($array['workspaceName']),
            DimensionSpacePoint::fromArray($array['source']),
            DimensionSpacePoint::fromArray($array['target']),
            ($baseWorkspaceName = $array['baseWorkspaceName'] ?? null)
                ? WorkspaceName::fromString($baseWorkspaceName)
                : null,
        );
    }

    public function createCopyForWorkspace(
        WorkspaceName $targetWorkspaceName,
    ): self {
        return new self(
            $targetWorkspaceName,
            $this->source,
            $this->target,
            $this->baseWorkspaceName,
        );
    }

    /**
     * @return array<string,\JsonSerializable>
     */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
