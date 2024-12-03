<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Subscription\Exception;

/**
 * Only thrown if there is no way to recover the started catchup.
 *
 * Todo move to delegating hook!
 *
 * @api
 */
final class CatchUpFailed extends \RuntimeException
{
}
