<?php

declare(strict_types=1);

namespace Neos\ContentRepository\BehavioralTests\Tests\Functional\Subscription;

use Neos\ContentRepository\Core\Projection\ProjectionSetupStatus;
use Neos\ContentRepository\Core\Subscription\Engine\SubscriptionEngineCriteria;
use Neos\ContentRepository\Core\Subscription\ProjectionSubscriptionStatus;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatuses;
use Neos\ContentRepository\Core\Subscription\SubscriptionError;
use Neos\ContentRepository\Core\Subscription\SubscriptionId;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\EventStore\Model\Event\SequenceNumber;

final class SubscriptionSetupTest extends AbstractSubscriptionEngineTestCase
{
    /** @test */
    public function setupOnEmptyDatabase()
    {
        $this->eventStore->setup();

        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->subscriptionEngine->setup();

        $this->fakeProjection->expects(self::exactly(2))->method('setUpStatus')->willReturn(ProjectionSetupStatus::ok());
        $actualStatuses = $this->subscriptionEngine->subscriptionStatuses();

        $expected = SubscriptionStatuses::fromArray([
            ProjectionSubscriptionStatus::create(
                subscriptionId: SubscriptionId::fromString('contentGraph'),
                subscriptionStatus: SubscriptionStatus::BOOTING,
                subscriptionPosition: SequenceNumber::none(),
                subscriptionError: null,
                setupStatus: ProjectionSetupStatus::ok(),
            ),
            ProjectionSubscriptionStatus::create(
                subscriptionId: SubscriptionId::fromString('Vendor.Package:FakeProjection'),
                subscriptionStatus: SubscriptionStatus::BOOTING,
                subscriptionPosition: SequenceNumber::none(),
                subscriptionError: null,
                setupStatus: ProjectionSetupStatus::ok(),
            ),
            ProjectionSubscriptionStatus::create(
                subscriptionId: SubscriptionId::fromString('Vendor.Package:SecondFakeProjection'),
                subscriptionStatus: SubscriptionStatus::BOOTING,
                subscriptionPosition: SequenceNumber::none(),
                subscriptionError: null,
                setupStatus: ProjectionSetupStatus::ok(),
            ),
        ]);

        self::assertEquals($expected, $actualStatuses);

        $this->expectOkayStatus('contentGraph', SubscriptionStatus::BOOTING, SequenceNumber::none());
        $this->expectOkayStatus('Vendor.Package:FakeProjection', SubscriptionStatus::BOOTING, SequenceNumber::none());
        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::BOOTING, SequenceNumber::none());

        self::assertEmpty(
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );
    }

    /** @test */
    public function filteringSetup()
    {
        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->fakeProjection->expects(self::once())->method('setUpStatus')->willReturn(ProjectionSetupStatus::ok());

        $this->eventStore->setup();

        $filter = SubscriptionEngineCriteria::create([SubscriptionId::fromString('Vendor.Package:FakeProjection')]);

        $result = $this->subscriptionEngine->setup($filter);
        self::assertNull($result->errors);

        $this->expectOkayStatus('Vendor.Package:FakeProjection', SubscriptionStatus::BOOTING, SequenceNumber::none());

        self::assertEquals(
            ProjectionSubscriptionStatus::create(
                subscriptionId: SubscriptionId::fromString('Vendor.Package:SecondFakeProjection'),
                subscriptionStatus: SubscriptionStatus::NEW,
                subscriptionPosition: SequenceNumber::none(),
                subscriptionError: null,
                setupStatus: ProjectionSetupStatus::ok()
            ),
            $this->subscriptionStatus('Vendor.Package:SecondFakeProjection')
        );
    }

    /** @test */
    public function setupIsInvokedForBootingSubscribers()
    {
        $this->fakeProjection->expects(self::exactly(2))->method('setUp');
        $this->fakeProjection->expects(self::any())->method('setUpStatus')->willReturn(ProjectionSetupStatus::ok());

        // hard reset, so that the tests actually need sql migrations
        $this->secondFakeProjection->dropTables();

        $this->eventStore->setup();

        // initial setup for FakeProjection

        $result = $this->subscriptionEngine->setup();
        self::assertNull($result->errors);
        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::BOOTING, SequenceNumber::none());

        // then an update is fetched, the status changes:

        $this->secondFakeProjection->schemaNeedsAdditionalColumn('column_after_update');

        self::assertEquals(
            ProjectionSubscriptionStatus::create(
                subscriptionId: SubscriptionId::fromString('Vendor.Package:SecondFakeProjection'),
                subscriptionStatus: SubscriptionStatus::BOOTING,
                subscriptionPosition: SequenceNumber::none(),
                subscriptionError: null,
                setupStatus: ProjectionSetupStatus::setupRequired('Requires 1 SQL statements')
            ),
            $this->subscriptionStatus('Vendor.Package:SecondFakeProjection')
        );

        $result = $this->subscriptionEngine->setup();
        self::assertNull($result->errors);

        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::BOOTING, SequenceNumber::none());
    }

    /** @test */
    public function setupIsInvokedForPreviouslyActiveSubscribers()
    {
        // Usecase: Setup a content repository and then when the subscribers are active, update to a new patch which requires a setup

        $this->fakeProjection->expects(self::exactly(2))->method('setUp');
        $this->fakeProjection->expects(self::once())->method('apply');
        $this->fakeProjection->expects(self::any())->method('setUpStatus')->willReturn(ProjectionSetupStatus::ok());

        // hard reset, so that the tests actually need sql migrations
        $this->secondFakeProjection->dropTables();

        $this->eventStore->setup();
        // setup subscription tables
        $result = $this->subscriptionEngine->setup(SubscriptionEngineCriteria::create([SubscriptionId::fromString('contentGraph')]));
        self::assertNull($result->errors);

        self::assertEquals(
            ProjectionSubscriptionStatus::create(
                subscriptionId: SubscriptionId::fromString('Vendor.Package:SecondFakeProjection'),
                subscriptionStatus: SubscriptionStatus::NEW,
                subscriptionPosition: SequenceNumber::none(),
                subscriptionError: null,
                setupStatus: ProjectionSetupStatus::setupRequired('Requires 1 SQL statements')
            ),
            $this->subscriptionStatus('Vendor.Package:SecondFakeProjection')
        );

        // initial setup for FakeProjection

        $result = $this->subscriptionEngine->setup();
        self::assertNull($result->errors);
        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::BOOTING, SequenceNumber::none());
        $result = $this->subscriptionEngine->boot();
        self::assertNull($result->errors);
        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::none());

        // regular work

        $this->commitExampleContentStreamEvent();
        $result = $this->subscriptionEngine->catchUpActive();
        self::assertNull($result->errors);

        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::fromInteger(1));

        // then an update is fetched, the status changes:

        $this->secondFakeProjection->schemaNeedsAdditionalColumn('column_after_update');

        self::assertEquals(
            ProjectionSubscriptionStatus::create(
                subscriptionId: SubscriptionId::fromString('Vendor.Package:SecondFakeProjection'),
                subscriptionStatus: SubscriptionStatus::ACTIVE,
                subscriptionPosition: SequenceNumber::fromInteger(1),
                subscriptionError: null,
                setupStatus: ProjectionSetupStatus::setupRequired('Requires 1 SQL statements')
            ),
            $this->subscriptionStatus('Vendor.Package:SecondFakeProjection')
        );

        $result = $this->subscriptionEngine->setup();
        self::assertNull($result->errors);

        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::fromInteger(1));
    }

    /** @test */
    public function failingSetupWillMarkProjectionAsErrored()
    {
        $this->fakeProjection->expects(self::once())->method('setUp')->willThrowException(
            $exception = new \RuntimeException('Projection could not be setup')
        );
        $this->fakeProjection->expects(self::once())->method('setUpStatus')->willReturn(ProjectionSetupStatus::setupRequired('Needs setup'));

        $this->eventStore->setup();

        $result = $this->subscriptionEngine->setup();
        self::assertSame('Projection could not be setup', $result->errors?->first()->message);

        $expectedFailure = ProjectionSubscriptionStatus::create(
            subscriptionId: SubscriptionId::fromString('Vendor.Package:FakeProjection'),
            subscriptionStatus: SubscriptionStatus::ERROR,
            subscriptionPosition: SequenceNumber::none(),
            subscriptionError: SubscriptionError::fromPreviousStatusAndException(SubscriptionStatus::NEW, $exception),
            setupStatus: ProjectionSetupStatus::setupRequired('Needs setup'),
        );

        self::assertEquals(
            $expectedFailure,
            $this->subscriptionStatus('Vendor.Package:FakeProjection')
        );
    }
}
