<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Feature;

use Neos\ContentRepository\Core\EventStore\InitiatingEventMetadata;
use Neos\ContentRepository\Core\Feature\Common\RebasableToOtherWorkspaceInterface;
use Neos\EventStore\Model\Event\EventMetadata;

/**
 * @internal
 */
final readonly class ExtractedCommand
{
    public function __construct(
        public RebasableToOtherWorkspaceInterface $originalCommand,
        public EventMetadata $initiatingMetaData,
        // todo SequenceNumber $originalSequenceNumber
    ) {
    }

    public static function fromEventMetaData(EventMetadata $eventMetadata): self
    {
        if (!isset($eventMetadata->value['commandClass'])) {
            throw new \RuntimeException('Command cannot be extracted from metadata, missing commandClass.', 1729847804);
        }

        $commandToRebaseClass = $eventMetadata->value['commandClass'];
        $commandToRebasePayload = $eventMetadata->value['commandPayload'];

        /**
         * the metadata will be added to all readable commands via
         * @see \Neos\ContentRepository\Core\Feature\Common\NodeAggregateEventPublisher::enrichWithCommand
         */
        // TODO: Add this logic to the NodeAggregateCommandHandler;
        // so that we can be sure these can be parsed again.

        if (!in_array(RebasableToOtherWorkspaceInterface::class, class_implements($commandToRebaseClass) ?: [], true)) {
            throw new \RuntimeException(sprintf(
                'Command "%s" can\'t be rebased because it does not implement %s',
                $commandToRebaseClass,
                RebasableToOtherWorkspaceInterface::class
            ), 1547815341);
        }
        /** @var class-string<RebasableToOtherWorkspaceInterface> $commandToRebaseClass */
        /** @var RebasableToOtherWorkspaceInterface $commandInstance */
        $commandInstance = $commandToRebaseClass::fromArray($commandToRebasePayload);
        return new self(
            $commandInstance,
            InitiatingEventMetadata::extractInitiatingMetadata($eventMetadata)
        );
    }
}
