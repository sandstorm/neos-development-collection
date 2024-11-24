<?php

declare(strict_types=1);

namespace Neos\ContentRepository\BehavioralTests\Tests\Functional\Subscription;

use Neos\ContentRepository\Core\Projection\ProjectionSetupStatus;
use Neos\ContentRepository\Core\Subscription\Engine\ProcessedResult;
use Neos\ContentRepository\Core\Subscription\ProjectionSubscriptionStatus;
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
    public function projectionIsDetachedOnCatchupActive()
    {
        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->fakeProjection->expects(self::any())->method('setUpStatus')->willReturn(ProjectionSetupStatus::ok());

        $this->eventStore->setup();
        $this->subscriptionEngine->setup();

        $result = $this->subscriptionEngine->boot();
        self::assertEquals(ProcessedResult::success(0), $result);

        $this->expectOkayStatus('Vendor.Package:FakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::none());

        // commit an event
        $this->commitExampleContentStreamEvent();

        $mockSettings = $this->getObject(ConfigurationManager::class)->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, 'Neos.ContentRepositoryRegistry');
        unset($mockSettings['contentRepositories'][$this->contentRepository->id->value]['projections']['Vendor.Package:FakeProjection']);
        $this->getObject(ContentRepositoryRegistry::class)->injectSettings($mockSettings);
        $this->getObject(ContentRepositoryRegistry::class)->resetFactoryInstance($this->contentRepository->id);
        $this->setupContentRepositoryDependencies($this->contentRepository->id);

        // todo status is stale??, should be DETACHED, and also cr:setup should marke detached projections?!!
        // $this->expectOkayStatus('Vendor.Package:FakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::none());

        $this->fakeProjection->expects(self::never())->method('apply');
        // catchup to mark detached subscribers
        $result = $this->subscriptionEngine->catchUpActive();
        // todo result should reflect that there was an detachment? Throw error in CR?
        self::assertEquals(ProcessedResult::success(1), $result);

        $expectedDetachedState = ProjectionSubscriptionStatus::create(
            subscriptionId: SubscriptionId::fromString('Vendor.Package:FakeProjection'),
            subscriptionStatus: SubscriptionStatus::DETACHED,
            subscriptionPosition: SequenceNumber::none(),
            subscriptionError: null,
            setupStatus: null // not calculate-able at this point!
        );
        self::assertEquals(
            $expectedDetachedState,
            $this->subscriptionStatus('Vendor.Package:FakeProjection')
        );

        // other projections are not interrupted
        self::assertEquals(
            [SequenceNumber::fromInteger(1)],
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );

        // succeeding catchup's do not handle the detached one:
        $this->commitExampleContentStreamEvent();
        $result = $this->subscriptionEngine->catchUpActive();
        self::assertEquals(ProcessedResult::success(1), $result);

        self::assertEquals(
            [SequenceNumber::fromInteger(1), SequenceNumber::fromInteger(2)],
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );

        // still detached
        self::assertEquals(
            $expectedDetachedState,
            $this->subscriptionStatus('Vendor.Package:FakeProjection')
        );
    }

    /** @test */
    public function projectionIsDetachedOnSetupAndReattachedIfPossible()
    {
        $this->fakeProjection->expects(self::exactly(2))->method('setUp');
        $this->fakeProjection->expects(self::once())->method('apply');
        $this->fakeProjection->expects(self::any())->method('setUpStatus')->willReturn(ProjectionSetupStatus::ok());

        $this->eventStore->setup();
        $this->subscriptionEngine->setup();

        $this->commitExampleContentStreamEvent();

        $result = $this->subscriptionEngine->boot();
        self::assertEquals(ProcessedResult::success(1), $result);

        $this->expectOkayStatus('Vendor.Package:FakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::fromInteger(1));

        // "uninstall" the projection
        $originalSettings = $this->getObject(ConfigurationManager::class)->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, 'Neos.ContentRepositoryRegistry');
        $mockSettings = $originalSettings;
        unset($mockSettings['contentRepositories'][$this->contentRepository->id->value]['projections']['Vendor.Package:FakeProjection']);
        $this->getObject(ContentRepositoryRegistry::class)->injectSettings($mockSettings);
        $this->getObject(ContentRepositoryRegistry::class)->resetFactoryInstance($this->contentRepository->id);
        $this->setupContentRepositoryDependencies($this->contentRepository->id);

        $this->fakeProjection->expects(self::never())->method('apply');
        // setup to find detached subscribers
        $result = $this->subscriptionEngine->setup();
        // todo result should reflect that there was an detachment?
        self::assertNull($result->errors);

        $expectedDetachedState = ProjectionSubscriptionStatus::create(
            subscriptionId: SubscriptionId::fromString('Vendor.Package:FakeProjection'),
            subscriptionStatus: SubscriptionStatus::DETACHED,
            subscriptionPosition: SequenceNumber::fromInteger(1),
            subscriptionError: null,
            setupStatus: null // not calculate-able at this point!
        );
        self::assertEquals(
            $expectedDetachedState,
            $this->subscriptionStatus('Vendor.Package:FakeProjection')
        );

        // another setup does not reattach, because there is no subscriber
        $result = $this->subscriptionEngine->setup();
        self::assertNull($result->errors);

        self::assertEquals(
            $expectedDetachedState,
            $this->subscriptionStatus('Vendor.Package:FakeProjection')
        );

        // "reinstall" the projection
        $this->getObject(ContentRepositoryRegistry::class)->injectSettings($originalSettings);
        $this->getObject(ContentRepositoryRegistry::class)->resetFactoryInstance($this->contentRepository->id);
        $this->setupContentRepositoryDependencies($this->contentRepository->id);

        self::assertEquals(
            ProjectionSubscriptionStatus::create(
                subscriptionId: SubscriptionId::fromString('Vendor.Package:FakeProjection'),
                subscriptionStatus: SubscriptionStatus::DETACHED,
                subscriptionPosition: SequenceNumber::fromInteger(1),
                subscriptionError: null,
                setupStatus: ProjectionSetupStatus::ok() // state _IS_ calculate-able at this point, todo better reflect meaning: is detached, but re-attachable!
            ),
            $this->subscriptionStatus('Vendor.Package:FakeProjection')
        );

        // setup does re-attach as the projection is found again
        $this->subscriptionEngine->setup();

        $this->expectOkayStatus('Vendor.Package:FakeProjection', SubscriptionStatus::BOOTING, SequenceNumber::fromInteger(1));
    }
}
