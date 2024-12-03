<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Projection\CatchUpHook;

use Neos\ContentRepository\Core\EventStore\EventInterface;
use Neos\ContentRepository\Core\Projection\ProjectionInterface;
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
     * AFTER the Database Lock is acquired, BEFORE any projection was called.
     *
     * Note that any errors thrown will cause the catchup to directly halt,
     * and no projections or their subscriber state are updated.
     */
    public function onBeforeCatchUp(SubscriptionStatus $subscriptionStatus): void;

    /**
     * This hook is called for every event during the catchup process, **before** the projection
     * is updated but in the same transaction: {@see ProjectionInterface::transactional()}.
     *
     * Note that any errors thrown will cause the catchup to directly halt,
     * and no projections or their subscriber state are updated, as the transaction is rolled back.
     */
    public function onBeforeEvent(EventInterface $eventInstance, EventEnvelope $eventEnvelope): void;

    /**
     * This hook is called for every event during the catchup process, **after** the projection
     * is updated but in the same transaction: {@see ProjectionInterface::transactional()}.
     *
     * Note that any errors thrown will cause the catchup to directly halt,
     * and no projections or their subscriber state are updated, as the transaction is rolled back.
     */
    public function onAfterEvent(EventInterface $eventInstance, EventEnvelope $eventEnvelope): void;

    /**
     * This hook is called at the END of a catch-up run
     * BEFORE the Database Lock is released, but AFTER the transaction is commited.
     *
     * Note that any errors thrown will bubble up and do not implicate the projection.
     * The projection and their new status and position will already be persisted without rollback.
     */
    public function onAfterCatchUp(): void;
}
