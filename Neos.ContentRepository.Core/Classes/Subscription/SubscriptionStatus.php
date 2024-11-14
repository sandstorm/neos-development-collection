<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Subscription;

/**
 * @api
 */
enum SubscriptionStatus : string
{
    case NEW = 'NEW';
    case BOOTING = 'BOOTING';
    case ACTIVE = 'ACTIVE';
    case PAUSED = 'PAUSED';
    case FINISHED = 'FINISHED';
    case DETACHED = 'DETACHED';
    case ERROR = 'ERROR';
}
