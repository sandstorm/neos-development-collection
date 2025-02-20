<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\CommandHandler;

use Neos\ContentRepository\Core\ContentRepository;

/**
 * Contract for a hook that is invoked just before any command is processed via {@see ContentRepository::handle()}
 *
 * A command hook can be used to replace/alter an incoming command before it is being passed to the corresponding {@see CommandHandlerInterface}.
 * This can be used to change or enrich the payload of the command.
 * A command hook can also be used to intercept commands based on their type or payload but this is not the intended use case because it can lead to a degraded user experience
 *
 * @api
 */
interface CommandHookInterface
{
    /**
     * @param CommandInterface $command The command that is about to be handled
     * @return CommandInterface This hook must return a command instance. It can be the unaltered incoming $command or a new instance
     */
    public function onBeforeHandle(CommandInterface $command): CommandInterface;
}
