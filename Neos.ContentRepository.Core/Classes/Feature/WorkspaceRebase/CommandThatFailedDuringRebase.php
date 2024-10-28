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

use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\EventStore\Model\Event\SequenceNumber;

/**
 * @api part of the exception exposed when rebasing failed
 */
final readonly class CommandThatFailedDuringRebase
{
    /**
     * @param CommandInterface $command the command that failed
     * @param \Throwable $exception how the command failed
     * @param SequenceNumber $sequenceNumber the event store sequence number of the event containing the command to be rebased
     */
    public function __construct(
        public CommandInterface $command,
        public \Throwable $exception,
        private SequenceNumber $sequenceNumber,
    ) {
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
}
