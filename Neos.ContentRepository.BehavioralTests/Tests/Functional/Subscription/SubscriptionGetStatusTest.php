<?php

declare(strict_types=1);

namespace Neos\ContentRepository\BehavioralTests\Tests\Functional\Subscription;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\Projection\ProjectionStatus;
use Neos\ContentRepository\Core\Subscription\Engine\SubscriptionEngineCriteria;
use Neos\ContentRepository\Core\Subscription\SubscriptionAndProjectionStatus;
use Neos\ContentRepository\Core\Subscription\SubscriptionAndProjectionStatuses;
use Neos\ContentRepository\Core\Subscription\SubscriptionId;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\EventStore\Model\Event\SequenceNumber;

final class SubscriptionGetStatusTest extends AbstractSubscriptionEngineTestCase
{
    /** @test */
    public function statusOnEmptyDatabase()
    {
        // fully drop the tables so that status has to recover if the subscriptions table is not there
        $this->resetDatabase(
            $this->getObject(Connection::class),
            $this->contentRepository->id,
            keepSchema: false
        );

        $actualStatuses = $this->subscriptionEngine->subscriptionStatuses();
        self::assertTrue($actualStatuses->isEmpty());

        self::assertNull(
            $this->subscriptionStatus('contentGraph')
        );
        self::assertNull(
            $this->subscriptionStatus('Vendor.Package:FakeProjection')
        );
        self::assertNull(
            $this->subscriptionStatus('Vendor.Package:SecondFakeProjection')
        );

        //
        // setup and fetch status
        //

        // only setup content graph so that the other projections are NEW, but still found
        $this->subscriptionEngine->setup(SubscriptionEngineCriteria::create([SubscriptionId::fromString('contentGraph')]));
        $this->expectOkayStatus('contentGraph', SubscriptionStatus::BOOTING, SequenceNumber::none());

        $this->fakeProjection->expects(self::once())->method('status')->willReturn(ProjectionStatus::setupRequired('fake needs setup.'));

        $actualStatuses = $this->subscriptionEngine->subscriptionStatuses();

        $expected = SubscriptionAndProjectionStatuses::fromArray([
            SubscriptionAndProjectionStatus::create(
                subscriptionId: SubscriptionId::fromString('contentGraph'),
                subscriptionStatus: SubscriptionStatus::BOOTING,
                subscriptionPosition: SequenceNumber::none(),
                subscriptionError: null,
                projectionStatus: ProjectionStatus::ok(),
            ),
            SubscriptionAndProjectionStatus::create(
                subscriptionId: SubscriptionId::fromString('Vendor.Package:FakeProjection'),
                subscriptionStatus: SubscriptionStatus::NEW,
                subscriptionPosition: SequenceNumber::none(),
                subscriptionError: null,
                projectionStatus: ProjectionStatus::setupRequired('fake needs setup.'),
            ),
            SubscriptionAndProjectionStatus::create(
                subscriptionId: SubscriptionId::fromString('Vendor.Package:SecondFakeProjection'),
                subscriptionStatus: SubscriptionStatus::NEW,
                subscriptionPosition: SequenceNumber::none(),
                subscriptionError: null,
                projectionStatus: ProjectionStatus::setupRequired('Requires 1 SQL statements'),
            ),
        ]);

        self::assertEquals($expected, $actualStatuses);
    }
}
