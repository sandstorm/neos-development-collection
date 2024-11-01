<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\CommandHandler;

use Neos\ContentRepository\Core\EventStore\EventsToPublish;

/**
 * TODO docs
 *
 * @api
 */
interface CommandHookInterface
{
    public function beforeHandle(CommandInterface $command): CommandInterface;
}
