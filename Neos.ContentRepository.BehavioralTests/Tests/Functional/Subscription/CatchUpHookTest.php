<?php

declare(strict_types=1);

namespace Neos\ContentRepository\BehavioralTests\Tests\Functional\Subscription;

use Neos\ContentRepository\Core\Feature\ContentStreamCreation\Event\ContentStreamWasCreated;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\EventEnvelope;

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

    /** @test */
    public function catchUpBeforeAndAfterCatchupAreRunForZeroEvents()
    {
        $this->eventStore->setup();
        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->fakeProjection->expects(self::never())->method('apply');
        $this->subscriptionEngine->setup();

        $this->catchupHookForFakeProjection->expects(self::once())->method('onBeforeCatchUp')->with(SubscriptionStatus::BOOTING);
        $this->catchupHookForFakeProjection->expects(self::never())->method('onBeforeEvent');
        $this->catchupHookForFakeProjection->expects(self::never())->method('onAfterEvent');
        $this->catchupHookForFakeProjection->expects(self::once())->method('onAfterCatchUp');

        $result = $this->subscriptionEngine->boot();
        self::assertNull($result->errors);

        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::fromInteger(0));
        self::assertEmpty($this->secondFakeProjection->getState()->findAppliedSequenceNumberValues());
    }

    /** @test */
    public function catchUpBeforeAndAfterCatchupAreNotRunIfNoSubscriberMatches()
    {
        $this->eventStore->setup();
        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->fakeProjection->expects(self::never())->method('apply');
        $this->subscriptionEngine->setup();

        $this->catchupHookForFakeProjection->expects(self::never())->method('onBeforeCatchUp');
        $this->catchupHookForFakeProjection->expects(self::never())->method('onBeforeEvent');
        $this->catchupHookForFakeProjection->expects(self::never())->method('onAfterEvent');
        $this->catchupHookForFakeProjection->expects(self::never())->method('onAfterCatchUp');

        $result = $this->subscriptionEngine->catchUpActive();
        self::assertNull($result->errors);
        self::assertEquals(0, $result->numberOfProcessedEvents);

        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::BOOTING, SequenceNumber::fromInteger(0));
        self::assertEmpty($this->secondFakeProjection->getState()->findAppliedSequenceNumberValues());
    }

    public function provideValidBatchSizes(): iterable
    {
        yield 'none' => [null];
        yield 'one' => [1];
        yield 'two' => [2];
        yield 'four' => [4];
        yield 'ten' => [10];
    }

    /**
     * @dataProvider provideValidBatchSizes
     * @test
     */
    public function catchUpHooksWithBatching(int|null $batchSize)
    {
        $this->eventStore->setup();
        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->fakeProjection->expects(self::exactly(4))->method('apply');
        $this->subscriptionEngine->setup();

        // commit events (will be batched in chunks of two)
        $this->commitExampleContentStreamEvent();
        $this->commitExampleContentStreamEvent();
        $this->commitExampleContentStreamEvent();
        $this->commitExampleContentStreamEvent();

        $this->catchupHookForFakeProjection->expects(self::once())->method('onBeforeCatchUp')->with(SubscriptionStatus::BOOTING);
        $this->catchupHookForFakeProjection->expects($i = self::exactly(4))->method('onBeforeEvent')->willReturnCallback(function ($_, EventEnvelope $eventEnvelope) use ($i) {
            match($i->getInvocationCount()) {
                1 => [
                    self::assertEquals(1, $eventEnvelope->sequenceNumber->value),
                    self::assertEquals([], $this->secondFakeProjection->getState()->findAppliedSequenceNumberValues())
                ],
                2 => [
                    self::assertEquals(2, $eventEnvelope->sequenceNumber->value),
                    self::assertEquals([1], $this->secondFakeProjection->getState()->findAppliedSequenceNumberValues())
                ],
                3 => [
                    self::assertEquals(3, $eventEnvelope->sequenceNumber->value),
                    self::assertEquals([1,2], $this->secondFakeProjection->getState()->findAppliedSequenceNumberValues())
                ],
                4 => [
                    self::assertEquals(4, $eventEnvelope->sequenceNumber->value),
                    self::assertEquals([1,2,3], $this->secondFakeProjection->getState()->findAppliedSequenceNumberValues())
                ],
            };
        });
        $this->catchupHookForFakeProjection->expects($i = self::exactly(4))->method('onAfterEvent')->willReturnCallback(function ($_, EventEnvelope $eventEnvelope) use ($i) {
            match($i->getInvocationCount()) {
                1 => [
                    self::assertEquals(1, $eventEnvelope->sequenceNumber->value),
                    self::assertEquals([1], $this->secondFakeProjection->getState()->findAppliedSequenceNumberValues())
                ],
                2 => [
                    self::assertEquals(2, $eventEnvelope->sequenceNumber->value),
                    self::assertEquals([1,2], $this->secondFakeProjection->getState()->findAppliedSequenceNumberValues())
                ],
                3 => [
                    self::assertEquals(3, $eventEnvelope->sequenceNumber->value),
                    self::assertEquals([1,2,3], $this->secondFakeProjection->getState()->findAppliedSequenceNumberValues())
                ],
                4 => [
                    self::assertEquals(4, $eventEnvelope->sequenceNumber->value),
                    self::assertEquals([1,2,3,4], $this->secondFakeProjection->getState()->findAppliedSequenceNumberValues())
                ],
            };
        });
        $this->catchupHookForFakeProjection->expects(self::once())->method('onAfterCatchUp');

        self::assertEmpty($this->secondFakeProjection->getState()->findAppliedSequenceNumberValues());

        $result = $this->subscriptionEngine->boot(batchSize: $batchSize);
        self::assertNull($result->errors);

        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::fromInteger(4));
        self::assertEquals([1,2,3,4], $this->secondFakeProjection->getState()->findAppliedSequenceNumberValues());
    }
}
