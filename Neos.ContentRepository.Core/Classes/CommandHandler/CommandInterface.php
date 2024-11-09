<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\CommandHandler;

use Neos\ContentRepository\Core\Feature\Common\RebasableToOtherWorkspaceInterface;

/**
 * Common (marker) interface for all public api commands of the content repository
 *
 * Note that this interface does not mark all commands.
 * Complex public commands will not be serializable on its own and are required to be serialized into a {@see RebasableToOtherWorkspaceInterface}
 *
 * @internal sealed interface. Custom commands cannot be handled and are no extension point!
 */
interface CommandInterface
{
}
