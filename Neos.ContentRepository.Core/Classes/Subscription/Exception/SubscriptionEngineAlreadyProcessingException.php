<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Subscription\Exception;

/**
 * @api
 */
final class SubscriptionEngineAlreadyProcessingException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Subscription engine is already processing');
    }
}
