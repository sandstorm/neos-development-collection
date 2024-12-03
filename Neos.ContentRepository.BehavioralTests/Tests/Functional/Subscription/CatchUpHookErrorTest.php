<?php

declare(strict_types=1);

namespace Neos\ContentRepository\BehavioralTests\Tests\Functional\Subscription;

use Neos\ContentRepository\Core\Feature\ContentStreamCreation\Event\ContentStreamWasCreated;
use Neos\ContentRepository\Core\Projection\ProjectionStatus;
use Neos\ContentRepository\Core\Subscription\Exception\CatchUpFailed;
use Neos\ContentRepository\Core\Subscription\ProjectionSubscriptionStatus;
use Neos\ContentRepository\Core\Subscription\SubscriptionError;
use Neos\ContentRepository\Core\Subscription\SubscriptionId;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\EventStore\Model\Event\SequenceNumber;

final class CatchUpHookErrorTest extends AbstractSubscriptionEngineTestCase
{
    /** @test */
    public function error_onBeforeEvent_projectionIsNotRun()
    {
        $this->eventStore->setup();
        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->fakeProjection->expects(self::once())->method('apply');
        $this->subscriptionEngine->setup();
        $this->subscriptionEngine->boot();

        // commit two events, we expect neither to be catchupd correctly because handing on the first fails
        $this->commitExampleContentStreamEvent();
        $this->commitExampleContentStreamEvent();

        $this->catchupHookForFakeProjection->expects(self::once())->method('onBeforeCatchUp')->with(SubscriptionStatus::ACTIVE);
        $this->catchupHookForFakeProjection->expects(self::once())->method('onBeforeEvent')->with(self::isInstanceOf(ContentStreamWasCreated::class))->willThrowException(
            $exception = new \RuntimeException('This catchup hook is kaputt.')
        );
        $this->catchupHookForFakeProjection->expects(self::never())->method('onAfterEvent');
        $this->catchupHookForFakeProjection->expects(self::never())->method('onAfterCatchUp');

        self::assertEmpty(
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );

        $actualException = null;
        try {
            $this->subscriptionEngine->catchUpActive();
        } catch (\Throwable $e) {
            $actualException = $e;
        }
        self::assertSame($exception, $actualException);
        self::assertSame('This catchup hook is kaputt.', $actualException->getMessage());

        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::none());

        // must be still empty because apply was never called
        self::assertEmpty(
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );
    }

    /** @test */
    public function error_onAfterEvent_projectionIsRolledBack()
    {
        $this->eventStore->setup();
        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->fakeProjection->expects(self::once())->method('apply');
        $this->subscriptionEngine->setup();
        $this->subscriptionEngine->boot();

        // commit an events, we expect neither to be catchupd correctly because handing on the first fails
        $this->commitExampleContentStreamEvent();
        $this->commitExampleContentStreamEvent();

        $this->catchupHookForFakeProjection->expects(self::once())->method('onBeforeCatchUp')->with(SubscriptionStatus::ACTIVE);
        $this->catchupHookForFakeProjection->expects(self::once())->method('onBeforeEvent')->with(self::isInstanceOf(ContentStreamWasCreated::class));
        $this->catchupHookForFakeProjection->expects(self::once())->method('onAfterEvent')->with(self::isInstanceOf(ContentStreamWasCreated::class))->willThrowException(
            $exception = new \RuntimeException('This catchup hook is kaputt.')
        );
        $this->catchupHookForFakeProjection->expects(self::never())->method('onAfterCatchUp');

        self::assertEmpty(
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );

        $actualException = null;
        try {
            $this->subscriptionEngine->catchUpActive();
        } catch (\Throwable $e) {
            $actualException = $e;
        }
        self::assertSame($exception, $actualException);
        self::assertSame('This catchup hook is kaputt.', $actualException->getMessage());

        // will be empty again because the full transaction was rolled back
        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::none());
        self::assertEmpty(
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );
    }

    /** @test */
    public function error_onBeforeCatchUp_abortsCatchup()
    {
        $this->eventStore->setup();
        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->fakeProjection->expects(self::never())->method('apply');
        $this->subscriptionEngine->setup();
        $this->subscriptionEngine->boot();

        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::none());

        // commit an event
        $this->commitExampleContentStreamEvent();

        $this->catchupHookForFakeProjection->expects(self::once())->method('onBeforeCatchUp')->with(SubscriptionStatus::ACTIVE)->willThrowException(
            new \RuntimeException('This catchup hook is kaputt.')
        );
        $this->catchupHookForFakeProjection->expects(self::never())->method('onBeforeEvent');
        $this->catchupHookForFakeProjection->expects(self::never())->method('onAfterEvent');
        $this->catchupHookForFakeProjection->expects(self::never())->method('onAfterCatchUp');

        $this->secondFakeProjection->injectSaboteur(fn () => self::fail('Projection apply is not expected to be called!'));

        self::assertEmpty(
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );

        $expectedFailure = null;
        try {
            $this->subscriptionEngine->catchUpActive();
        } catch (\Throwable $e) {
            $expectedFailure = $e;
        }
        self::assertInstanceOf(CatchUpFailed::class, $expectedFailure);

        self::assertSame($expectedFailure->getMessage(), 'Subscriber "Vendor.Package:SecondFakeProjection" failed onBeforeCatchUp: This catchup hook is kaputt.');

        // still the initial status
        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::none());

        // must be still empty because apply was never called
        self::assertEmpty(
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );
    }

    /** @test */
    public function error_onAfterCatchUp_crashesAfterProjectionsArePersisted()
    {
        $this->eventStore->setup();
        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->fakeProjection->expects(self::once())->method('apply');
        $this->subscriptionEngine->setup();
        $this->subscriptionEngine->boot();

        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::none());

        // commit an event
        $this->commitExampleContentStreamEvent();

        $this->catchupHookForFakeProjection->expects(self::once())->method('onBeforeCatchUp');
        $this->catchupHookForFakeProjection->expects(self::once())->method('onBeforeEvent');
        $this->catchupHookForFakeProjection->expects(self::once())->method('onAfterEvent');
        // todo test that other catchup hooks are still run and all errors are collected!
        $this->catchupHookForFakeProjection->expects(self::once())->method('onAfterCatchUp')->willThrowException(
            new \RuntimeException('This catchup hook is kaputt.')
        );

        self::assertEmpty(
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );

        $expectedFailure = null;
        try {
            $this->subscriptionEngine->catchUpActive();
        } catch (\Throwable $e) {
            $expectedFailure = $e;
        }
        self::assertInstanceOf(CatchUpFailed::class, $expectedFailure);

        self::assertSame($expectedFailure->getMessage(), 'Subscriber "Vendor.Package:SecondFakeProjection" failed onAfterCatchUp: This catchup hook is kaputt.');

        // one event is applied!
        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::fromInteger(1));
        self::assertEquals(
            [SequenceNumber::fromInteger(1)],
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );
    }

    /** @test */
    public function error_onAfterCatchUp_crashesAfterProjectionsArePersisted_withProjectionError()
    {
        $this->eventStore->setup();
        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->fakeProjection->expects(self::once())->method('apply');
        $this->subscriptionEngine->setup();
        $this->subscriptionEngine->boot();

        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::none());

        // commit an event
        $this->commitExampleContentStreamEvent();

        $this->catchupHookForFakeProjection->expects(self::once())->method('onBeforeCatchUp');
        $this->catchupHookForFakeProjection->expects(self::once())->method('onBeforeEvent');
        $this->catchupHookForFakeProjection->expects(self::never())->method('onAfterEvent');
        $this->catchupHookForFakeProjection->expects(self::once())->method('onAfterCatchUp')->willThrowException(
            new \RuntimeException('This catchup hook is kaputt.')
        );

        $innerException = new \RuntimeException('Projection is kaputt.');
        $this->secondFakeProjection->injectSaboteur(fn () => throw $innerException);

        self::assertEmpty(
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );

        $expectedFailure = null;
        try {
            $this->subscriptionEngine->catchUpActive();
        } catch (\Throwable $e) {
            $expectedFailure = $e;
        }
        self::assertInstanceOf(CatchUpFailed::class, $expectedFailure);

        self::assertSame($expectedFailure->getMessage(), 'Subscriber "Vendor.Package:SecondFakeProjection" failed onAfterCatchUp: This catchup hook is kaputt.');

        $expectedFailure = ProjectionSubscriptionStatus::create(
            subscriptionId: SubscriptionId::fromString('Vendor.Package:SecondFakeProjection'),
            subscriptionStatus: SubscriptionStatus::ERROR,
            subscriptionPosition: SequenceNumber::none(),
            subscriptionError: SubscriptionError::fromPreviousStatusAndException(SubscriptionStatus::ACTIVE, $innerException),
            setupStatus: ProjectionStatus::ok(),
        );

        // projection is still marked as error
        self::assertEquals(
            $expectedFailure,
            $this->subscriptionStatus('Vendor.Package:SecondFakeProjection')
        );
        self::assertEmpty(
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );
    }
}
