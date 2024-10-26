<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Feature;

use Neos\ContentRepository\Core\Feature\Common\MatchableWithNodeIdToPublishOrDiscardInterface;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Dto\NodeIdsToPublishOrDiscard;
use Neos\EventStore\Model\EventStream\EventStreamInterface;

/**
 * @internal
 * @implements \IteratorAggregate<ExtractedCommand>
 */
class ExtractedCommands implements \IteratorAggregate
{
    /**
     * @var array<ExtractedCommand>
     */
    private array $items;

    public function __construct(
        ExtractedCommand ...$items
    ) {
        $this->items = $items;
    }

    public static function createFromEventStream(EventStreamInterface $eventStream): self
    {
        $commands = [];
        foreach ($eventStream as $eventEnvelope) {
            if ($eventEnvelope->event->metadata && isset($eventEnvelope->event->metadata?->value['commandClass'])) {
                $commands[$eventEnvelope->sequenceNumber->value] = ExtractedCommand::fromEventMetaData($eventEnvelope->event->metadata);
            }
        }

        return new ExtractedCommands(...$commands);
    }

    /**
     * @return array{ExtractedCommands,ExtractedCommands}
     */
    public function separateMatchingAndRemainingCommands(
        NodeIdsToPublishOrDiscard $nodeIdsToPublishOrDiscard
    ): array {
        $matchingCommands = [];
        $remainingCommands = [];
        foreach ($this->items as $extractedCommand) {
            $originalCommand = $extractedCommand->originalCommand;
            if (!$originalCommand instanceof MatchableWithNodeIdToPublishOrDiscardInterface) {
                throw new \Exception(
                    'Command class ' . get_class($originalCommand) . ' does not implement '
                    . MatchableWithNodeIdToPublishOrDiscardInterface::class,
                    1645393655
                );
            }
            if (self::commandMatchesAtLeastOneNode($originalCommand, $nodeIdsToPublishOrDiscard)) {
                $matchingCommands[] = $extractedCommand;
            } else {
                $remainingCommands[] = $extractedCommand;
            }
        }
        return [
            new ExtractedCommands(...$matchingCommands),
            new ExtractedCommands(...$remainingCommands)
        ];
    }

    private static function commandMatchesAtLeastOneNode(
        MatchableWithNodeIdToPublishOrDiscardInterface $command,
        NodeIdsToPublishOrDiscard $nodeIds,
    ): bool {
        foreach ($nodeIds as $nodeId) {
            if ($command->matchesNodeId($nodeId)) {
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
