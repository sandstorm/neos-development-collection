<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Projection;

/**
 * @api
 */
enum ProjectionSetupStatusType
{
    case OK;
    case SETUP_REQUIRED;
    case ERROR;
}
