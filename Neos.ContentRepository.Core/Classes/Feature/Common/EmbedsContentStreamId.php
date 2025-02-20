<?php

/*
 * This file is part of the Neos.ContentRepository package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Feature\Common;

use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;

/**
 * This interface is implemented by **events** which contain ContentStreamId.
 *
 * This is relevant e.g. for content cache flushing as a result of an event.
 *
 * @internal
 */
interface EmbedsContentStreamId
{
    public function getContentStreamId(): ContentStreamId;
}
