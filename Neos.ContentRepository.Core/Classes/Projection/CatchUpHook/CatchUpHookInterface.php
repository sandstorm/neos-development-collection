<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Projection\CatchUpHook;

use Neos\ContentRepository\Core\EventStore\EventInterface;
use Neos\ContentRepository\Core\Projection\ProjectionInterface;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\EventStore\Model\EventEnvelope;

/**
 * This is an api with which you can hook into the catch-up process of a projection.
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
     * Note that any errors thrown will be ignored and the catchup will start as normal.
     * The collect errors will be returned and rethrown by the content repository.
     *
     * @throws CatchUpHookFailed
     */
    public function onBeforeCatchUp(SubscriptionStatus $subscriptionStatus): void;

    /**
     * This hook is called for every event during the catchup process, **before** the projection
     * is updated but in the same transaction: {@see ProjectionInterface::transactional()}.
     *
     * Note that any errors thrown will be ignored and the catchup will continue as normal.
     * The collect errors will be returned and rethrown by the content repository.
     *
     * @throws CatchUpHookFailed
     */
    public function onBeforeEvent(EventInterface $eventInstance, EventEnvelope $eventEnvelope): void;

    /**
     * This hook is called for every event during the catchup process, **after** the projection
     * is updated but in the same transaction: {@see ProjectionInterface::transactional()}.
     *
     * Note that any errors thrown will be ignored and the catchup will continue as normal.
     * The collect errors will be returned and rethrown by the content repository.
     *
     * @throws CatchUpHookFailed
     */
    public function onAfterEvent(EventInterface $eventInstance, EventEnvelope $eventEnvelope): void;

    /**
     * This hook is called for each batch of processed events during the catchup process, **after** the projection
     * is updated and the transaction is commited.
     *
     * It can happen that this method is called even without having seen Events in the meantime.
     *
     * If there exist more events which need to be processed, the database lock
     * is directly acquired again after it is released.
     */
    public function onAfterBatchCompleted(): void;

    /**
     * This hook is called at the END of a catch-up run
     * BEFORE the Database Lock is released, but AFTER the transaction is commited.
     *
     * The projection and their new status and position are already persisted.
     *
     * Note that any errors thrown will be ignored and the catchup will finish as normal.
     * The collect errors will be returned and rethrown by the content repository.
     *
     * @throws CatchUpHookFailed
     */
    public function onAfterCatchUp(): void;
}
