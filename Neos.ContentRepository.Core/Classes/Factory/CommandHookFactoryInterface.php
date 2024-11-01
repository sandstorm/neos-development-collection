<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Factory;

use Neos\ContentRepository\Core\CommandHandler\CommandHookInterface;

/**
 * @internal
 */
interface CommandHookFactoryInterface
{
    public function build(
        CommandHooksFactoryDependencies $commandHooksFactoryDependencies,
    ): CommandHookInterface;
}
