<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Projection\CatchUpHook;

use Neos\ContentRepository\Core\EventStore\EventInterface;
use Neos\ContentRepository\Core\Subscription\Engine\SubscriptionEngine;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\EventStore\Model\EventEnvelope;

/**
 * This is an internal API with which you can hook into the catch-up process of a Projection.
 *
 * To register such a CatchUpHook, create a corresponding {@see CatchUpHookFactoryInterface}
 * and pass it to {@see ProjectionFactoryInterface::build()}.
 *
 * @api
 */
interface CatchUpHookInterface
{
    /**
     * This hook is called at the beginning of a catch-up run;
     * BEFORE the Database Lock is acquired ({@see SubscriptionEngine::run()}).
     *
     * @return void
     */
    public function onBeforeCatchUp(SubscriptionStatus $subscriptionStatus): void;

    /**
     * This hook is called for every event during the catchup process, **before** the projection
     * is updated. Thus, this hook runs AFTER the database lock is acquired.
     */
    public function onBeforeEvent(EventInterface $eventInstance, EventEnvelope $eventEnvelope): void;

    /**
     * This hook is called for every event during the catchup process, **after** the projection
     * is updated. Thus, this hook runs AFTER the database lock is acquired.
     */
    public function onAfterEvent(EventInterface $eventInstance, EventEnvelope $eventEnvelope): void;

    /**
     * This hook is called at the END of a catch-up run
     *
     * At this point, the Database Lock has already been released.
     */
    public function onAfterCatchUp(): void;
}
