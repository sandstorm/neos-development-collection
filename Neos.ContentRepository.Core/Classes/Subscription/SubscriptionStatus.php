<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Subscription;

/**
 * @api part of the subscription status
 */
enum SubscriptionStatus : string
{
    case NEW = 'NEW';
    case BOOTING = 'BOOTING';
    case ACTIVE = 'ACTIVE';
    case DETACHED = 'DETACHED';
    case ERROR = 'ERROR';
}
