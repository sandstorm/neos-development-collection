<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Projection\CatchUpHook;

use Neos\ContentRepository\Core\EventStore\EventInterface;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\EventStore\Model\EventEnvelope;

/**
 * Internal helper class for running *multiple* CatchUpHooks inside
 * a Projection update cycle.
 *
 * @internal
 */
final class DelegatingCatchUpHook implements CatchUpHookInterface
{
    /**
     * @var CatchUpHookInterface[]
     */
    private array $catchUpHooks;

    public function __construct(
        CatchUpHookInterface ...$catchUpHooks
    ) {
        $this->catchUpHooks = $catchUpHooks;
    }

    public function onBeforeCatchUp(SubscriptionStatus $subscriptionStatus): void
    {
        foreach ($this->catchUpHooks as $catchUpHook) {
            $catchUpHook->onBeforeCatchUp($subscriptionStatus);
        }
    }

    public function onBeforeEvent(EventInterface $eventInstance, EventEnvelope $eventEnvelope): void
    {
        foreach ($this->catchUpHooks as $catchUpHook) {
            $catchUpHook->onBeforeEvent($eventInstance, $eventEnvelope);
        }
    }

    public function onAfterEvent(EventInterface $eventInstance, EventEnvelope $eventEnvelope): void
    {
        foreach ($this->catchUpHooks as $catchUpHook) {
            $catchUpHook->onAfterEvent($eventInstance, $eventEnvelope);
        }
    }

    public function onAfterCatchUp(): void
    {
        foreach ($this->catchUpHooks as $catchUpHook) {
            $catchUpHook->onAfterCatchUp();
        }
    }
}
