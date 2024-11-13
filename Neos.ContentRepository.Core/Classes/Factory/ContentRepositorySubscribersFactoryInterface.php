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

namespace Neos\ContentRepository\Core\Factory;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Subscription\Subscriber\Subscribers;

/**
 * Main factory to build a {@see ContentRepository} object.
 *
 * @api for implementers of framework integration (e.g. standalone CR)
 */
interface ContentRepositorySubscribersFactoryInterface
{
    public function build(SubscriberFactoryDependencies $dependencies): Subscribers;
}
