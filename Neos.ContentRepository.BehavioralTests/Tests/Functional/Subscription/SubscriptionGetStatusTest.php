<?php

declare(strict_types=1);

namespace Neos\ContentRepository\BehavioralTests\Tests\Functional\Subscription;

use Doctrine\DBAL\Connection;

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

        $this->fakeProjection->expects(self::never())->method('status');

        $actualStatuses = $this->subscriptionService->subscriptionEngine->subscriptionStatuses();
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
    }
}
