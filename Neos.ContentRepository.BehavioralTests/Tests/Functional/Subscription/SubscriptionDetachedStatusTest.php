<?php

declare(strict_types=1);

namespace Neos\ContentRepository\BehavioralTests\Tests\Functional\Subscription;

use Neos\ContentRepository\Core\Projection\ProjectionStatus;
use Neos\ContentRepository\Core\Subscription\Engine\ProcessedResult;
use Neos\ContentRepository\Core\Subscription\SubscriptionAndProjectionStatus;
use Neos\ContentRepository\Core\Subscription\SubscriptionId;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\Flow\Configuration\ConfigurationManager;

final class SubscriptionDetachedStatusTest extends AbstractSubscriptionEngineTestCase
{
    /** @after */
    public function resetContentRepositoryRegistry(): void
    {
        $originalSettings = $this->getObject(ConfigurationManager::class)->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, 'Neos.ContentRepositoryRegistry');
        $this->getObject(ContentRepositoryRegistry::class)->injectSettings($originalSettings);
    }

    /** @test */
    public function projectionIsDetachedIfConfigurationIsRemoved()
    {
        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->fakeProjection->expects(self::any())->method('status')->willReturn(ProjectionStatus::ok());

        $this->subscriptionService->setupEventStore();
        $this->subscriptionService->subscriptionEngine->setup();

        $result = $this->subscriptionService->subscriptionEngine->boot();
        self::assertEquals(ProcessedResult::success(0), $result);

        $this->expectOkayStatus('Vendor.Package:FakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::none());

        // commit an event
        $this->commitExampleContentStreamEvent();

        $mockSettings = $this->getObject(ConfigurationManager::class)->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, 'Neos.ContentRepositoryRegistry');
        unset($mockSettings['contentRepositories'][$this->contentRepository->id->value]['projections']['Vendor.Package:FakeProjection']);
        $this->getObject(ContentRepositoryRegistry::class)->injectSettings($mockSettings);
        $this->getObject(ContentRepositoryRegistry::class)->resetFactoryInstance($this->contentRepository->id);
        $this->setupContentRepositoryDependencies($this->contentRepository->id);

        // todo status is stale??, should be DETACHED
        // $this->expectOkayStatus('Vendor.Package:FakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::none());

        $this->fakeProjection->expects(self::never())->method('apply');
        // catchup or anything that finds detached subscribers
        $result = $this->subscriptionEngine->catchUpActive();
        self::assertEquals(ProcessedResult::success(1), $result);

        self::assertEquals(
            SubscriptionAndProjectionStatus::create(
                subscriptionId: SubscriptionId::fromString('Vendor.Package:FakeProjection'),
                subscriptionStatus: SubscriptionStatus::DETACHED,
                subscriptionPosition: SequenceNumber::none(),
                subscriptionError: null,
                projectionStatus: null // not calculate-able at this point!
            ),
            $this->subscriptionStatus('Vendor.Package:FakeProjection')
        );
    }
}
