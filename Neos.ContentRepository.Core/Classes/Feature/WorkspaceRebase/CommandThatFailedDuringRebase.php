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

namespace Neos\ContentRepository\Core\Feature\WorkspaceRebase;

use Neos\ContentRepository\Core\Feature\Common\RebasableToOtherWorkspaceInterface;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNodeAndSerializedProperties;
use Neos\ContentRepository\Core\Feature\NodeDisabling\Command\DisableNodeAggregate;
use Neos\ContentRepository\Core\Feature\NodeDisabling\Command\EnableNodeAggregate;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetSerializedNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeMove\Command\MoveNodeAggregate;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Command\SetSerializedNodeReferences;
use Neos\ContentRepository\Core\Feature\NodeRemoval\Command\RemoveNodeAggregate;
use Neos\ContentRepository\Core\Feature\NodeTypeChange\Command\ChangeNodeAggregateType;
use Neos\ContentRepository\Core\Feature\NodeVariation\Command\CreateNodeVariant;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\TagSubtree;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\UntagSubtree;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\EventStore\Model\Event\SequenceNumber;

/**
 * @api part of the exception exposed when rebasing failed
 */
final readonly class CommandThatFailedDuringRebase
{
    /**
     * @internal
     */
    public function __construct(
        private RebasableToOtherWorkspaceInterface $command,
        private \Throwable $exception,
        private SequenceNumber $sequenceNumber,
    ) {
    }

    /**
     * The node aggregate id of the failed command
     */
    public function getAffectedNodeAggregateId(): ?NodeAggregateId
    {
        return match ($this->command::class) {
            MoveNodeAggregate::class,
            SetSerializedNodeProperties::class,
            CreateNodeAggregateWithNodeAndSerializedProperties::class,
            TagSubtree::class,
            DisableNodeAggregate::class,
            UntagSubtree::class,
            EnableNodeAggregate::class,
            RemoveNodeAggregate::class,
            ChangeNodeAggregateType::class,
            CreateNodeVariant::class => $this->command->nodeAggregateId,
            SetSerializedNodeReferences::class => $this->command->sourceNodeAggregateId,
            default => null
        };
    }

    /**
     * How the command failed that was attempted to be rebased
     */
    public function getException(): \Throwable
    {
        return $this->exception;
    }

    /**
     * The event store sequence number of the event containing the command to be rebased
     *
     * @internal exposed for testing
     */
    public function getSequenceNumber(): SequenceNumber
    {
        return $this->sequenceNumber;
    }

    /**
     * The command that failed
     *
     * @internal exposed for testing and experimental use cases
     */
    public function getCommand(): RebasableToOtherWorkspaceInterface
    {
        return $this->command;
    }
}
