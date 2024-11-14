<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Subscription;

/**
 * @api
 */
enum RunMode : string
{
    case FROM_BEGINNING = 'FROM_BEGINNING';
    case FROM_NOW = 'FROM_NOW';
    case ONCE = 'ONCE';
}
