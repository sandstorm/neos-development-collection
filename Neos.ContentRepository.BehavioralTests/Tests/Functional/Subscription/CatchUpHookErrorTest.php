<?php

declare(strict_types=1);

namespace Neos\ContentRepository\BehavioralTests\Tests\Functional\Subscription;

use Neos\ContentRepository\Core\Feature\ContentStreamCreation\Event\ContentStreamWasCreated;
use Neos\ContentRepository\Core\Projection\CatchUpHook\CatchUpHookFailed;
use Neos\ContentRepository\Core\Projection\ProjectionStatus;
use Neos\ContentRepository\Core\Subscription\Engine\Error;
use Neos\ContentRepository\Core\Subscription\Engine\Errors;
use Neos\ContentRepository\Core\Subscription\Engine\ProcessedResult;
use Neos\ContentRepository\Core\Subscription\ProjectionSubscriptionStatus;
use Neos\ContentRepository\Core\Subscription\SubscriptionError;
use Neos\ContentRepository\Core\Subscription\SubscriptionId;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\EventEnvelope;

final class CatchUpHookErrorTest extends AbstractSubscriptionEngineTestCase
{
    /** @test */
    public function error_onBeforeEvent_isIgnoredAndCollected()
    {
        $this->eventStore->setup();
        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->fakeProjection->expects(self::exactly(2))->method('apply');
        $this->subscriptionEngine->setup();
        $this->subscriptionEngine->boot();

        // commit two events. we expect that the hook will throw for both events but the catchup is NOT halted
        $this->commitExampleContentStreamEvent();
        $this->commitExampleContentStreamEvent();

        $this->catchupHookForFakeProjection->expects(self::once())->method('onBeforeCatchUp')->with(SubscriptionStatus::ACTIVE);

        $exception = new \RuntimeException('This catchup hook is kaputt.');
        $this->catchupHookForFakeProjection->expects($invokedCount = self::exactly(2))->method('onBeforeEvent')->willReturnCallback(function ($_, EventEnvelope $eventEnvelope) use ($invokedCount, $exception) {
            match ($invokedCount->getInvocationCount()) {
                1 => self::assertSame(1, $eventEnvelope->sequenceNumber->value),
                2 => self::assertSame(2, $eventEnvelope->sequenceNumber->value),
            };
            throw $exception;
        });
        $this->catchupHookForFakeProjection->expects(self::exactly(2))->method('onAfterEvent');
        $this->catchupHookForFakeProjection->expects(self::once())->method('onAfterBatchCompleted');
        $this->catchupHookForFakeProjection->expects(self::once())->method('onAfterCatchUp');

        self::assertEmpty(
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );

        $expectedWrappedException = new CatchUpHookFailed(
            'Hook "" failed "onBeforeEvent": This catchup hook is kaputt.',
            1733243960,
            $exception,
            []
        );

        // two errors for both of the events
        $result = $this->subscriptionEngine->catchUpActive();
        self::assertEquals(
            ProcessedResult::failed(
                2,
                Errors::fromArray([
                    Error::forSubscription(SubscriptionId::fromString('Vendor.Package:SecondFakeProjection'), $expectedWrappedException),
                    Error::forSubscription(SubscriptionId::fromString('Vendor.Package:SecondFakeProjection'), $expectedWrappedException),
                ])
            ),
            $result
        );

        // both events are applied still
        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::fromInteger(2));
        self::assertEquals(
            [SequenceNumber::fromInteger(1), SequenceNumber::fromInteger(2)],
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );
    }

    /** @test */
    public function error_onAfterEvent_isIgnoredAndCollected()
    {
        $this->eventStore->setup();
        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->fakeProjection->expects(self::exactly(2))->method('apply');
        $this->subscriptionEngine->setup();
        $this->subscriptionEngine->boot();

        // commit two events. we expect that the hook will throw for both events but the catchup is NOT halted
        $this->commitExampleContentStreamEvent();
        $this->commitExampleContentStreamEvent();

        $this->catchupHookForFakeProjection->expects(self::once())->method('onBeforeCatchUp')->with(SubscriptionStatus::ACTIVE);
        $this->catchupHookForFakeProjection->expects(self::exactly(2))->method('onBeforeEvent')->with(self::isInstanceOf(ContentStreamWasCreated::class));
        $exception = new \RuntimeException('This catchup hook is kaputt.');
        $this->catchupHookForFakeProjection->expects($invokedCount = self::exactly(2))->method('onAfterEvent')->willReturnCallback(function ($_, EventEnvelope $eventEnvelope) use ($invokedCount, $exception) {
            match ($invokedCount->getInvocationCount()) {
                1 => self::assertSame(1, $eventEnvelope->sequenceNumber->value),
                2 => self::assertSame(2, $eventEnvelope->sequenceNumber->value),
            };
            throw $exception;
        });
        $this->catchupHookForFakeProjection->expects(self::once())->method('onAfterBatchCompleted');
        $this->catchupHookForFakeProjection->expects(self::once())->method('onAfterCatchUp'); // todo assert no parameters?!

        self::assertEmpty(
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );

        $expectedWrappedException = new CatchUpHookFailed(
            'Hook "" failed "onAfterEvent": This catchup hook is kaputt.',
            1733243960,
            $exception,
            []
        );

        // two errors for both of the events
        $result = $this->subscriptionEngine->catchUpActive();
        self::assertEquals(
            ProcessedResult::failed(
                2,
                Errors::fromArray([
                    Error::forSubscription(SubscriptionId::fromString('Vendor.Package:SecondFakeProjection'), $expectedWrappedException),
                    Error::forSubscription(SubscriptionId::fromString('Vendor.Package:SecondFakeProjection'), $expectedWrappedException),
                ])
            ),
            $result
        );

        // both events are applied still
        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::fromInteger(2));
        self::assertEquals(
            [SequenceNumber::fromInteger(1), SequenceNumber::fromInteger(2)],
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );
    }

    /** @test */
    public function error_onBeforeCatchUp_isIgnoredAndCollected()
    {
        $this->eventStore->setup();
        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->fakeProjection->expects(self::exactly(2))->method('apply');
        $this->subscriptionEngine->setup();
        $this->subscriptionEngine->boot();

        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::none());

        // commit two events. we expect that the hook will throw for both events but the catchup is NOT halted
        $this->commitExampleContentStreamEvent();
        $this->commitExampleContentStreamEvent();

        $this->catchupHookForFakeProjection->expects(self::once())->method('onBeforeCatchUp')->with(SubscriptionStatus::ACTIVE)->willThrowException(
            $exception = new \RuntimeException('This catchup hook is kaputt.')
        );
        $this->catchupHookForFakeProjection->expects(self::exactly(2))->method('onBeforeEvent');
        $this->catchupHookForFakeProjection->expects(self::exactly(2))->method('onAfterEvent');
        $this->catchupHookForFakeProjection->expects(self::once())->method('onAfterBatchCompleted');
        $this->catchupHookForFakeProjection->expects(self::once())->method('onAfterCatchUp');

        self::assertEmpty(
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );

        $expectedWrappedException = new CatchUpHookFailed(
            'Hook "" failed "onBeforeCatchUp": This catchup hook is kaputt.',
            1733243960,
            $exception,
            []
        );

        $result = $this->subscriptionEngine->catchUpActive();
        self::assertEquals(
            ProcessedResult::failed(
                2,
                Errors::fromArray([
                    Error::forSubscription(SubscriptionId::fromString('Vendor.Package:SecondFakeProjection'), $expectedWrappedException),
                ])
            ),
            $result
        );

        // both events are applied still
        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::fromInteger(2));
        self::assertEquals(
            [SequenceNumber::fromInteger(1), SequenceNumber::fromInteger(2)],
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );
    }

    /** @test */
    public function error_onAfterBatchCompleted_isIgnoredAndCollected()
    {
        $this->eventStore->setup();
        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->fakeProjection->expects(self::exactly(2))->method('apply');
        $this->subscriptionEngine->setup();
        $this->subscriptionEngine->boot();

        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::none());

        // commit two events. we expect that the hook will throw for both events but the catchup is NOT halted
        $this->commitExampleContentStreamEvent();
        $this->commitExampleContentStreamEvent();

        $this->catchupHookForFakeProjection->expects(self::once())->method('onBeforeCatchUp');
        $this->catchupHookForFakeProjection->expects(self::exactly(2))->method('onBeforeEvent');
        $this->catchupHookForFakeProjection->expects(self::exactly(2))->method('onAfterEvent');
        $this->catchupHookForFakeProjection->expects(self::once())->method('onAfterBatchCompleted')->willThrowException(
            $exception = new \RuntimeException('This catchup hook is kaputt.')
        );
        $this->catchupHookForFakeProjection->expects(self::once())->method('onAfterCatchUp');

        self::assertEmpty(
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );

        $expectedWrappedException = new CatchUpHookFailed(
            'Hook "" failed "onAfterBatchCompleted": This catchup hook is kaputt.',
            1733243960,
            $exception,
            []
        );

        $result = $this->subscriptionEngine->catchUpActive();
        self::assertEquals(
            ProcessedResult::failed(
                2,
                Errors::fromArray([
                    Error::forSubscription(SubscriptionId::fromString('Vendor.Package:SecondFakeProjection'), $expectedWrappedException),
                ])
            ),
            $result
        );

        // both events are applied still
        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::fromInteger(2));
        self::assertEquals(
            [SequenceNumber::fromInteger(1), SequenceNumber::fromInteger(2)],
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );
    }

    /** @test */
    public function error_onAfterCatchUp_isIgnoredAndCollected()
    {
        $this->eventStore->setup();
        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->fakeProjection->expects(self::exactly(2))->method('apply');
        $this->subscriptionEngine->setup();
        $this->subscriptionEngine->boot();

        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::none());

        // commit two events. we expect that the hook will throw for both events but the catchup is NOT halted
        $this->commitExampleContentStreamEvent();
        $this->commitExampleContentStreamEvent();

        $this->catchupHookForFakeProjection->expects(self::once())->method('onBeforeCatchUp');
        $this->catchupHookForFakeProjection->expects(self::exactly(2))->method('onBeforeEvent');
        $this->catchupHookForFakeProjection->expects(self::exactly(2))->method('onAfterEvent');
        $this->catchupHookForFakeProjection->expects(self::once())->method('onAfterBatchCompleted');
        // todo test that other catchup hooks are still run and all errors are collected!
        $this->catchupHookForFakeProjection->expects(self::once())->method('onAfterCatchUp')->willThrowException(
            $exception = new \RuntimeException('This catchup hook is kaputt.')
        );

        self::assertEmpty(
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );

        $expectedWrappedException = new CatchUpHookFailed(
            'Hook "" failed "onAfterCatchUp": This catchup hook is kaputt.',
            1733243960,
            $exception,
            []
        );

        $result = $this->subscriptionEngine->catchUpActive();
        self::assertEquals(
            ProcessedResult::failed(
                2,
                Errors::fromArray([
                    Error::forSubscription(SubscriptionId::fromString('Vendor.Package:SecondFakeProjection'), $expectedWrappedException),
                ])
            ),
            $result
        );

        // both events are applied still
        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::fromInteger(2));
        self::assertEquals(
            [SequenceNumber::fromInteger(1), SequenceNumber::fromInteger(2)],
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );
    }

    /** @test */
    public function error_onAfterCatchUp_isIgnoredAndCollected_withProjectionError()
    {
        $this->eventStore->setup();
        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->fakeProjection->expects(self::exactly(2))->method('apply');
        $this->subscriptionEngine->setup();
        $this->subscriptionEngine->boot();

        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::none());

        // commit two events. we expect that the hook will throw for both events but the catchup is NOT halted
        $this->commitExampleContentStreamEvent();
        $this->commitExampleContentStreamEvent();

        $this->catchupHookForFakeProjection->expects(self::once())->method('onBeforeCatchUp');
        // only the onBeforeEvent hook will be invoked as afterward the projection errored
        $this->catchupHookForFakeProjection->expects(self::exactly(1))->method('onBeforeEvent');
        $this->catchupHookForFakeProjection->expects(self::never())->method('onAfterEvent');
        $this->catchupHookForFakeProjection->expects(self::once())->method('onAfterBatchCompleted');
        $this->catchupHookForFakeProjection->expects(self::once())->method('onAfterCatchUp')->willThrowException(
            $exception = new \RuntimeException('This catchup hook is kaputt.')
        );

        $innerException = new \RuntimeException('Projection is kaputt.');
        $this->secondFakeProjection->injectSaboteur(fn () => throw $innerException);

        self::assertEmpty(
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );

        $expectedWrappedException = new CatchUpHookFailed(
            'Hook "" failed "onAfterCatchUp": This catchup hook is kaputt.',
            1733243960,
            $exception,
            []
        );

        // two errors for both of the events
        $result = $this->subscriptionEngine->catchUpActive();

        self::assertEquals(
            ProcessedResult::failed(
                2,
                Errors::fromArray([
                    Error::forSubscription(SubscriptionId::fromString('Vendor.Package:SecondFakeProjection'), $innerException),
                    Error::forSubscription(SubscriptionId::fromString('Vendor.Package:SecondFakeProjection'), $expectedWrappedException),
                ])
            ),
            $result
        );

        $expectedFailure = ProjectionSubscriptionStatus::create(
            subscriptionId: SubscriptionId::fromString('Vendor.Package:SecondFakeProjection'),
            subscriptionStatus: SubscriptionStatus::ERROR,
            subscriptionPosition: SequenceNumber::none(),
            subscriptionError: SubscriptionError::fromPreviousStatusAndException(SubscriptionStatus::ACTIVE, $innerException),
            setupStatus: ProjectionStatus::ok(),
        );

        // projection is marked as error
        self::assertEquals(
            $expectedFailure,
            $this->subscriptionStatus('Vendor.Package:SecondFakeProjection')
        );
        self::assertEmpty(
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );
    }

    /** @test */
    public function error_onAfterEvent_stopsEngineAfterFirstBatch()
    {
        $this->eventStore->setup();
        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->fakeProjection->expects(self::once())->method('apply');
        $this->subscriptionEngine->setup();

        // commit two events. we expect that the hook will throw the first event and due to the batching its halted
        $this->commitExampleContentStreamEvent();
        $this->commitExampleContentStreamEvent();

        $this->catchupHookForFakeProjection->expects(self::once())->method('onBeforeCatchUp')->with(SubscriptionStatus::BOOTING);
        $this->catchupHookForFakeProjection->expects(self::once())->method('onBeforeEvent')->with(self::isInstanceOf(ContentStreamWasCreated::class));
        $exception = new \RuntimeException('This catchup hook is kaputt.');
        $this->catchupHookForFakeProjection->expects(self::once())->method('onAfterEvent')->willThrowException(
            $exception
        );
        $this->catchupHookForFakeProjection->expects(self::once())->method('onAfterBatchCompleted');
        $this->catchupHookForFakeProjection->expects(self::once())->method('onAfterCatchUp');

        self::assertEmpty(
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );

        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::BOOTING, SequenceNumber::fromInteger(0));

        $expectedWrappedException = new CatchUpHookFailed(
            'Hook "" failed "onAfterEvent": This catchup hook is kaputt.',
            1733243960,
            $exception,
            []
        );

        // one error
        $result = $this->subscriptionEngine->boot(batchSize: 1);
        self::assertEquals(
            ProcessedResult::failed(
                1,
                Errors::fromArray([
                    Error::forSubscription(SubscriptionId::fromString('Vendor.Package:SecondFakeProjection'), $expectedWrappedException),
                ])
            ),
            $result
        );

        // only one event is applied
        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::BOOTING, SequenceNumber::fromInteger(1));
        self::assertEquals(
            [SequenceNumber::fromInteger(1)],
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );
    }
}
