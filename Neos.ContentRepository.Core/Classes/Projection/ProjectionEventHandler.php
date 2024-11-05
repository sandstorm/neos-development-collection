<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Projection;

use Neos\ContentRepository\Core\EventStore\EventInterface;
use Neos\ContentRepository\Core\Projection\CatchUpHook\CatchUpHookInterface;
use Neos\ContentRepository\Core\Subscription\Subscriber\EventHandlerInterface;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\EventStore\Model\EventEnvelope;

/**
 * @internal
 */
final readonly class ProjectionEventHandler implements EventHandlerInterface
{
    /**
     * @param ProjectionInterface<ProjectionStateInterface> $projection
     */
    private function __construct(
        public ProjectionInterface $projection,
        private CatchUpHookInterface|null $catchUpHook,
    ) {
    }

    /**
     * @param ProjectionInterface<ProjectionStateInterface> $projection
     * @return self
     */
    public static function create(ProjectionInterface $projection): self
    {
        return new self($projection, null);
    }

    /**
     * @param ProjectionInterface<ProjectionStateInterface> $projection
     */
    public static function createWithCatchUpHook(ProjectionInterface $projection, CatchUpHookInterface $catchUpHook): self
    {
        return new self($projection, $catchUpHook);
    }

    public function onBeforeCatchUp(SubscriptionStatus $subscriptionStatus): void
    {
        $this->catchUpHook?->onBeforeCatchUp($subscriptionStatus);
    }

    public function handle(EventInterface $event, EventEnvelope $eventEnvelope): void
    {
        $this->catchUpHook?->onBeforeEvent($event, $eventEnvelope);
        $this->projection->apply($event, $eventEnvelope);
        $this->catchUpHook?->onAfterEvent($event, $eventEnvelope);
    }

    public function onAfterCatchUp(): void
    {
        $this->catchUpHook?->onAfterCatchUp();
    }
}
