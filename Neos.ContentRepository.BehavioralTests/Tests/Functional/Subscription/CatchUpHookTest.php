<?php

declare(strict_types=1);

namespace Neos\ContentRepository\BehavioralTests\Tests\Functional\Subscription;

use Neos\ContentRepository\Core\Feature\ContentStreamCreation\Event\ContentStreamWasCreated;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\EventStore\Model\Event\SequenceNumber;

final class CatchUpHookTest extends AbstractSubscriptionEngineTestCase
{
    /** @test */
    public function catchUpHooksAreExecutedAndCanAccessTheCorrectProjectionsState()
    {
        $this->eventStore->setup();
        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->fakeProjection->expects(self::once())->method('apply');
        $this->subscriptionEngine->setup();
        $this->subscriptionEngine->boot();

        // commit an event
        $this->commitExampleContentStreamEvent();

        $expectNoHandledEvents = fn () => self::assertEmpty(
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );

        $expectOneHandledEvent = fn () => self::assertEquals(
            [
                SequenceNumber::fromInteger(1)
            ],
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );

        $this->catchupHookForFakeProjection->expects(self::once())->method('onBeforeCatchUp')->with(SubscriptionStatus::ACTIVE)->willReturnCallback($expectNoHandledEvents);
        $this->catchupHookForFakeProjection->expects(self::once())->method('onBeforeEvent')->with(self::isInstanceOf(ContentStreamWasCreated::class))->willReturnCallback($expectNoHandledEvents);
        $this->catchupHookForFakeProjection->expects(self::once())->method('onAfterEvent')->with(self::isInstanceOf(ContentStreamWasCreated::class))->willReturnCallback($expectOneHandledEvent);
        $this->catchupHookForFakeProjection->expects(self::once())->method('onAfterCatchUp')->willReturnCallback($expectOneHandledEvent);

        $expectNoHandledEvents();

        $result = $this->subscriptionEngine->catchUpActive();
        self::assertNull($result->errors);

        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::fromInteger(1));

        $expectOneHandledEvent();
    }
}
