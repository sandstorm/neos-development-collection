<?php

declare(strict_types=1);

namespace Neos\ContentRepository\BehavioralTests\TestSuite;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\Projection\ProjectionStateInterface;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
final class DebugEventProjectionState implements ProjectionStateInterface
{
    public function __construct(
        private string $tableNamePrefix,
        private Connection $dbal
    ) {
    }

    /**
     * @return iterable<SequenceNumber>
     */
    public function findAppliedSequenceNumbers(): iterable
    {
        return array_map(
            fn ($value) => SequenceNumber::fromInteger((int)$value['sequenceNumber']),
            $this->dbal->fetchAllAssociative("SELECT sequenceNumber from {$this->tableNamePrefix}")
        );
    }
}
