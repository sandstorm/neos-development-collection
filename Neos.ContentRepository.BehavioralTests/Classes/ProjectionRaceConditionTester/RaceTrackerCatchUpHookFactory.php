<?php

/*
 * This file is part of the Neos.ContentRepository.BehavioralTests package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\ContentRepository\BehavioralTests\ProjectionRaceConditionTester;

use Neos\ContentRepository\Core\Projection\CatchUpHookFactoryInterface;
use Neos\ContentRepository\Core\Projection\CatchUpHookInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphReadModelInterface;
use Neos\ContentRepository\Core\Projection\ProjectionStateInterface;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;

/**
 * For full docs and context, see {@see RaceTrackerCatchUpHook}
 *
 * @implements CatchUpHookFactoryInterface<ContentGraphReadModelInterface>
 * @internal
 */
final class RaceTrackerCatchUpHookFactory implements CatchUpHookFactoryInterface
{
    public function build(ContentRepositoryId $contentRepositoryId, ProjectionStateInterface $projectionState): CatchUpHookInterface
    {
        return new RaceTrackerCatchUpHook();
    }
}
