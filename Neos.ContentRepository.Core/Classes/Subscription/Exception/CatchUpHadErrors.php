<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Subscription\Exception;

use Neos\ContentRepository\Core\Projection\CatchUpHook\CatchUpHookFailed;
use Neos\ContentRepository\Core\Subscription\Engine\Error;
use Neos\ContentRepository\Core\Subscription\Engine\Errors;

/**
 * Thrown if the subscribers could not be catchup without encountering errors.
 *
 * Still, as we collect the errors and don't halt the process the system will be still up-to-date as far as possible.
 *
 * Following reasons would trigger this error:
 *
 * - A projection has a failure in its code. Then the projection is rolled back to the last event and put into ERROR state.
 *   An exception will be part of this collection indicating this change. Further catchup's will not attempt to update that
 *   projection again, as it has to be fixed and reactivated first.
 *
 * - A catchup hook contains an error. In this case the projections is further updated and also all further catchup errors
 *   collected. This results in a {@see CatchUpHookFailed} exception.
 *
 * @api
 */
final class CatchUpHadErrors extends \RuntimeException
{
    /**
     * @internal
     */
    public static function createFromErrors(Errors $errors): self
    {
        /** @var non-empty-array<Error> $errors */
        $errors = iterator_to_array($errors);
        $firstError = array_shift($errors);

        $additionalFailedSubscribers = array_map(fn (Error $error) => $error->subscriptionId->value, $errors);

        $additionalErrors = $additionalFailedSubscribers === [] ? '' : sprintf(' | And subscribers %s with additional errors.', join(', ', $additionalFailedSubscribers));
        $exceptionMessage = sprintf('Exception in subscriber "%s" while catching up: %s%s', $firstError->subscriptionId->value, $firstError->message, $additionalErrors);

        throw new self($exceptionMessage, 1732132930, $firstError->throwable);
    }
}
