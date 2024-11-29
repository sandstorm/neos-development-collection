<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Feature;

use Neos\ContentRepository\Core\Feature\Common\RebasableToOtherWorkspaceInterface;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNodeAndSerializedProperties;
use Neos\ContentRepository\Core\Feature\NodeDisabling\Command\DisableNodeAggregate;
use Neos\ContentRepository\Core\Feature\NodeDisabling\Command\EnableNodeAggregate;
use Neos\ContentRepository\Core\Feature\NodeDuplication\Command\CopyNodesRecursively;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetSerializedNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeMove\Command\MoveNodeAggregate;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Command\SetSerializedNodeReferences;
use Neos\ContentRepository\Core\Feature\NodeRemoval\Command\RemoveNodeAggregate;
use Neos\ContentRepository\Core\Feature\NodeRenaming\Command\ChangeNodeAggregateName;
use Neos\ContentRepository\Core\Feature\NodeTypeChange\Command\ChangeNodeAggregateType;
use Neos\ContentRepository\Core\Feature\NodeVariation\Command\CreateNodeVariant;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\TagSubtree;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\UntagSubtree;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateIds;
use Neos\EventStore\Model\EventStream\EventStreamInterface;

/**
 * @internal
 * @implements \IteratorAggregate<RebaseableCommand>
 */
class RebaseableCommands implements \IteratorAggregate
{
    /**
     * @var array<RebaseableCommand>
     */
    private array $items;

    public function __construct(
        RebaseableCommand ...$items
    ) {
        $this->items = $items;
    }

    public static function extractFromEventStream(EventStreamInterface $eventStream): self
    {
        $commands = [];
        foreach ($eventStream as $eventEnvelope) {
            if ($eventEnvelope->event->metadata && isset($eventEnvelope->event->metadata?->value['commandClass'])) {
                $commands[] = RebaseableCommand::extractFromEventEnvelope($eventEnvelope);
            }
        }

        return new RebaseableCommands(...$commands);
    }

    /**
     * @return array{RebaseableCommands,RebaseableCommands}
     */
    public function separateMatchingAndRemainingCommands(
        NodeAggregateIds $nodeIdsToMatch
    ): array {
        $matchingCommands = [];
        $remainingCommands = [];
        foreach ($this->items as $extractedCommand) {
            if (self::commandMatchesAtLeastOneNode($extractedCommand->originalCommand, $nodeIdsToMatch)) {
                $matchingCommands[] = $extractedCommand;
            } else {
                $remainingCommands[] = $extractedCommand;
            }
        }
        return [
            new RebaseableCommands(...$matchingCommands),
            new RebaseableCommands(...$remainingCommands)
        ];
    }

    private static function commandMatchesAtLeastOneNode(
        RebasableToOtherWorkspaceInterface $command,
        NodeAggregateIds $nodeIdsToMatch,
    ): bool {
        foreach ($nodeIdsToMatch as $nodeId) {
            /**
             * This match must contain all commands which are working with individual nodes, such that they are
             * filterable whether they are applying their action to a NodeIdToPublish.
             *
             * This is needed to publish and discard individual nodes.
             */
            $matches = match($command::class) {
                CreateNodeAggregateWithNodeAndSerializedProperties::class,
                DisableNodeAggregate::class,
                EnableNodeAggregate::class,
                SetSerializedNodeProperties::class,
                MoveNodeAggregate::class,
                RemoveNodeAggregate::class,
                ChangeNodeAggregateName::class,
                ChangeNodeAggregateType::class,
                CreateNodeVariant::class,
                TagSubtree::class,
                UntagSubtree::class
                    => $command->nodeAggregateId->equals($nodeId),
                CopyNodesRecursively::class => $command->nodeAggregateIdMapping->getNewNodeAggregateId(
                    $command->nodeTreeToInsert->nodeAggregateId
                )?->equals($nodeId),
                SetSerializedNodeReferences::class => $command->sourceNodeAggregateId->equals($nodeId),
                // todo return false instead to allow change to be still kept?
                default => throw new \RuntimeException(sprintf('Command %s cannot be matched against a node aggregate id. Partial publish not possible.', $command::class), 1645393655)
            };
            if ($matches) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function getIterator(): \Traversable
    {
        yield from $this->items;
    }
}
