<?php

declare(strict_types=1);

namespace Neos\ContentRepository\BehavioralTests\Tests\Functional\Subscription;

use Neos\ContentRepository\Core\Feature\ContentStreamCreation\Event\ContentStreamWasCreated;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Command\CreateRootWorkspace;
use Neos\ContentRepository\Core\Projection\ProjectionStatus;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Core\Subscription\Engine\Error;
use Neos\ContentRepository\Core\Subscription\Engine\Errors;
use Neos\ContentRepository\Core\Subscription\Engine\ProcessedResult;
use Neos\ContentRepository\Core\Subscription\SubscriptionAndProjectionStatus;
use Neos\ContentRepository\Core\Subscription\SubscriptionError;
use Neos\ContentRepository\Core\Subscription\SubscriptionId;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\EventEnvelope;
use PHPUnit\Framework\MockObject\Stub\Exception as WillThrowException;

final class ProjectionErrorTest extends AbstractSubscriptionEngineTestCase
{
    /** @test */
    public function projectionWithError()
    {
        $this->subscriptionService->setupEventStore();
        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->subscriptionService->subscriptionEngine->setup();
        $this->fakeProjection->expects(self::any())->method('status')->willReturn(ProjectionStatus::ok());
        $result = $this->subscriptionService->subscriptionEngine->boot();
        self::assertEquals(ProcessedResult::success(0), $result);
        $this->expectOkayStatus('contentGraph', SubscriptionStatus::ACTIVE, SequenceNumber::none());
        $this->expectOkayStatus('Vendor.Package:FakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::none());

        // commit an event
        $this->commitExampleContentStreamEvent();

        // catchup active tries to apply the commited event
        $this->fakeProjection->expects(self::exactly(3))->method('apply')->with(self::isInstanceOf(ContentStreamWasCreated::class))->willReturnOnConsecutiveCalls(
            new WillThrowException($exception = new \RuntimeException('This projection is kaputt.')),
            new WillThrowException(new \Error('Something really wrong.')),
            new WillThrowException(new \InvalidArgumentException('Dead.')),
        );
        // TIME 1
        $result = $this->subscriptionService->subscriptionEngine->catchUpActive();
        self::assertEquals(ProcessedResult::failed(1, Errors::fromArray([Error::fromSubscriptionIdAndException(SubscriptionId::fromString('Vendor.Package:FakeProjection'), $exception)])), $result);

        self::assertEquals(
            SubscriptionAndProjectionStatus::create(
                subscriptionId: SubscriptionId::fromString('Vendor.Package:FakeProjection'),
                subscriptionStatus: SubscriptionStatus::ERROR,
                subscriptionPosition: SequenceNumber::none(),
                subscriptionError: SubscriptionError::fromPreviousStatusAndException(SubscriptionStatus::ACTIVE, $exception),
                projectionStatus: ProjectionStatus::ok(),
            ),
            $this->subscriptionStatus('Vendor.Package:FakeProjection')
        );
        $this->expectOkayStatus('contentGraph', SubscriptionStatus::ACTIVE, SequenceNumber::fromInteger(1));

        // TIME 2
        $result = $this->subscriptionService->subscriptionEngine->catchUpActive();
        self::assertTrue($result->hasFailed());
        self::assertEquals($result->errors->first()->message, 'Something really wrong.');
        self::assertEquals($this->subscriptionStatus('Vendor.Package:FakeProjection')->subscriptionError->errorMessage, 'Something really wrong.');

        // TIME 3
        $result = $this->subscriptionService->subscriptionEngine->catchUpActive();
        self::assertTrue($result->hasFailed());
        self::assertEquals($result->errors->first()->message, 'Dead.');
        self::assertEquals($this->subscriptionStatus('Vendor.Package:FakeProjection')->subscriptionError->errorMessage, 'Dead.');

        // succeeding calls, nothing to do.
        $result = $this->subscriptionService->subscriptionEngine->catchUpActive();
        self::assertEquals(ProcessedResult::success(0), $result);
        // still dead
        self::assertEquals($this->subscriptionStatus('Vendor.Package:FakeProjection')->subscriptionError->errorMessage, 'Dead.');
    }

    /** @test */
    public function fixProjectionWithError()
    {
        $this->subscriptionService->setupEventStore();
        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->fakeProjection->expects(self::any())->method('status')->willReturn(ProjectionStatus::ok());
        $this->subscriptionService->subscriptionEngine->setup();
        $this->subscriptionService->subscriptionEngine->boot();

        // commit an event
        $this->commitExampleContentStreamEvent();

        // catchup active tries to apply the commited event
        $this->fakeProjection->expects(self::exactly(2))->method('apply')->with(self::isInstanceOf(ContentStreamWasCreated::class))->willReturnOnConsecutiveCalls(
            new WillThrowException($exception = new \RuntimeException('This projection is kaputt.')),
            null // okay again
        );

        $expectedFailure = SubscriptionAndProjectionStatus::create(
            subscriptionId: SubscriptionId::fromString('Vendor.Package:FakeProjection'),
            subscriptionStatus: SubscriptionStatus::ERROR,
            subscriptionPosition: SequenceNumber::none(),
            subscriptionError: SubscriptionError::fromPreviousStatusAndException(SubscriptionStatus::ACTIVE, $exception),
            projectionStatus: ProjectionStatus::ok(),
        );

        // TIME 1
        $result = $this->subscriptionService->subscriptionEngine->catchUpActive();
        self::assertTrue($result->hasFailed());

        self::assertEquals(
            $expectedFailure,
            $this->subscriptionStatus('Vendor.Package:FakeProjection')
        );

        // todo BOOT and SETUP should not attempt to retry?!
        // setup does not change anything
        // $result = $this->subscriptionService->subscriptionEngine->setup();
        // self::assertNull($result->errors);
        // boot neither
        // $result = $this->subscriptionService->subscriptionEngine->boot();
        // self::assertNull($result->errors);
        // still the same state
        // self::assertEquals(
        //     $expectedFailure,
        //     $this->subscriptionStatus('Vendor.Package:FakeProjection')
        // );

        $this->fakeProjection->expects(self::once())->method('resetState');

        $result = $this->subscriptionService->subscriptionEngine->reset();
        self::assertNull($result->errors);

        // expect the subscriptionError to be reset to null
        $this->expectOkayStatus('Vendor.Package:FakeProjection', SubscriptionStatus::BOOTING, SequenceNumber::none());

        $result = $this->subscriptionService->subscriptionEngine->boot();
        self::assertNull($result->errors);

        $this->expectOkayStatus('Vendor.Package:FakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::fromInteger(1));
    }

    /** @test */
    public function projectionIsRolledBackAfterError()
    {
        $this->subscriptionService->setupEventStore();
        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->fakeProjection->expects(self::once())->method('apply');
        $this->subscriptionService->subscriptionEngine->setup();
        $this->subscriptionService->subscriptionEngine->boot();

        // commit an event
        $this->commitExampleContentStreamEvent();

        $exception = new \RuntimeException('This projection is kaputt.');

        $this->secondFakeProjection->injectSaboteur(fn () => throw $exception);

        $expectedFailure = SubscriptionAndProjectionStatus::create(
            subscriptionId: SubscriptionId::fromString('Vendor.Package:SecondFakeProjection'),
            subscriptionStatus: SubscriptionStatus::ERROR,
            subscriptionPosition: SequenceNumber::none(),
            subscriptionError: SubscriptionError::fromPreviousStatusAndException(SubscriptionStatus::ACTIVE, $exception),
            projectionStatus: ProjectionStatus::ok(),
        );

        self::assertEmpty(
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );

        $result = $this->subscriptionService->subscriptionEngine->catchUpActive();
        self::assertSame($result->errors?->first()->message, 'This projection is kaputt.');

        self::assertEquals(
            $expectedFailure,
            $this->subscriptionStatus('Vendor.Package:SecondFakeProjection')
        );

        // should be empty as we need an exact once delivery
        self::assertEmpty(
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );

        $this->secondFakeProjection->killSaboteur();

        // todo find way to retry projection? catchup force?
    }

    /** @test */
    public function projectionIsRolledBackAfterErrorButKeepsSuccessFullEvents()
    {
        $this->subscriptionService->setupEventStore();
        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->fakeProjection->expects(self::exactly(2))->method('apply');
        $this->subscriptionService->subscriptionEngine->setup();
        $this->subscriptionService->subscriptionEngine->boot();

        // commit two events
        $this->commitExampleContentStreamEvent();
        $this->commitExampleContentStreamEvent();

        $exception = new \RuntimeException('Event 2 is kaputt.');

        // fail at the second event
        $this->secondFakeProjection->injectSaboteur(
            fn (EventEnvelope $eventEnvelope) =>
            $eventEnvelope->sequenceNumber->value === 2
                ? throw $exception
                : null
        );

        self::assertEmpty(
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );

        $result = $this->subscriptionService->subscriptionEngine->catchUpActive();
        self::assertTrue($result->hasFailed());

        $expectedFailure = SubscriptionAndProjectionStatus::create(
            subscriptionId: SubscriptionId::fromString('Vendor.Package:SecondFakeProjection'),
            subscriptionStatus: SubscriptionStatus::ERROR,
            subscriptionPosition: SequenceNumber::fromInteger(1),
            subscriptionError: SubscriptionError::fromPreviousStatusAndException(SubscriptionStatus::ACTIVE, $exception),
            projectionStatus: ProjectionStatus::ok(),
        );

        self::assertEquals(
            $expectedFailure,
            $this->subscriptionStatus('Vendor.Package:SecondFakeProjection')
        );

        // the first successful event is applied and committet:
        self::assertEquals(
            [SequenceNumber::fromInteger(1)],
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );
    }

    /** @test */
    public function projectionErrorWithMultipleProjectionsInContentRepositoryHandle()
    {
        $this->subscriptionService->setupEventStore();
        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->fakeProjection->expects(self::any())->method('status')->willReturn(ProjectionStatus::ok());
        $this->subscriptionService->subscriptionEngine->setup();
        $this->subscriptionService->subscriptionEngine->boot();

        $this->fakeProjection->expects(self::exactly(2))->method('apply')->with(self::isInstanceOf(ContentStreamWasCreated::class))->willThrowException(
            $originalException = new \RuntimeException('This projection is kaputt.'),
        );

        $handleException = null;
        try {
            $this->contentRepository->handle(CreateRootWorkspace::create(WorkspaceName::fromString('root'), ContentStreamId::fromString('root-cs')));
        } catch (\RuntimeException $exception) {
            $handleException = $exception;
        }
        self::assertNotNull($handleException);
        self::assertEquals('Exception in subscriber "Vendor.Package:FakeProjection" while catching up: This projection is kaputt.', $handleException->getMessage());
        self::assertSame($originalException, $handleException->getPrevious());

        // workspace is created
        self::assertNotNull($this->contentRepository->findWorkspaceByName(WorkspaceName::fromString('root')));
        $this->expectOkayStatus('contentGraph', SubscriptionStatus::ACTIVE, SequenceNumber::fromInteger(2));

        $handleException = null;
        try {
            $this->contentRepository->handle(CreateRootWorkspace::create(WorkspaceName::fromString('root-two'), ContentStreamId::fromString('root-cs-two')));
        } catch (\RuntimeException $exception) {
            $handleException = $exception;
        }
        self::assertNotNull($handleException);

        // workspace two is created. The fake projection is still dead and FAILS on the FIRST event, but the content graph gets only the new event:
        self::assertNotNull($this->contentRepository->findWorkspaceByName(WorkspaceName::fromString('root-two')));
        $this->expectOkayStatus('contentGraph', SubscriptionStatus::ACTIVE, SequenceNumber::fromInteger(4));
    }
}
