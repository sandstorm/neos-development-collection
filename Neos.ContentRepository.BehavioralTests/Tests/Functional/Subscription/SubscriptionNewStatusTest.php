<?php

declare(strict_types=1);

namespace Neos\ContentRepository\BehavioralTests\Tests\Functional\Subscription;

use Neos\ContentRepository\Core\Projection\ProjectionInterface;
use Neos\ContentRepository\Core\Projection\ProjectionStateInterface;
use Neos\ContentRepository\Core\Projection\ProjectionSetupStatus;
use Neos\ContentRepository\Core\Subscription\Engine\ProcessedResult;
use Neos\ContentRepository\Core\Subscription\Engine\SubscriptionEngineCriteria;
use Neos\ContentRepository\Core\Subscription\ProjectionSubscriptionStatus;
use Neos\ContentRepository\Core\Subscription\SubscriptionId;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\ContentRepository\TestSuite\Fakes\FakeProjectionFactory;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\Flow\Configuration\ConfigurationManager;

final class SubscriptionNewStatusTest extends AbstractSubscriptionEngineTestCase
{
    /** @after */
    public function resetContentRepositoryRegistry(): void
    {
        $originalSettings = $this->getObject(ConfigurationManager::class)->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, 'Neos.ContentRepositoryRegistry');
        $this->getObject(ContentRepositoryRegistry::class)->injectSettings($originalSettings);
    }

    /** @test */
    public function newProjectionIsFoundWhenConfigurationIsAdded()
    {
        $this->fakeProjection->expects(self::exactly(2))->method('setUp');
        $this->fakeProjection->expects(self::any())->method('setUpStatus')->willReturn(ProjectionSetupStatus::ok());

        $this->eventStore->setup();
        $this->subscriptionEngine->setup();

        $result = $this->subscriptionEngine->boot();
        self::assertEquals(ProcessedResult::success(0), $result);

        self::assertNull($this->subscriptionStatus('Vendor.Package:NewFakeProjection'));
        $this->expectOkayStatus('Vendor.Package:FakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::none());

        $newFakeProjection = $this->getMockBuilder(ProjectionInterface::class)->disableAutoReturnValueGeneration()->getMock();
        $newFakeProjection->method('getState')->willReturn(new class implements ProjectionStateInterface {});
        $newFakeProjection->expects(self::exactly(3))->method('setUpStatus')->willReturnOnConsecutiveCalls(
            ProjectionSetupStatus::setupRequired('Set me up'),
            ProjectionSetupStatus::ok(),
            ProjectionSetupStatus::ok(),
        );

        FakeProjectionFactory::setProjection(
            'newFake',
            $newFakeProjection
        );

        $mockSettings = $this->getObject(ConfigurationManager::class)->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, 'Neos.ContentRepositoryRegistry');
        $mockSettings['contentRepositories'][$this->contentRepository->id->value]['projections']['Vendor.Package:NewFakeProjection'] = [
            'factoryObjectName' => FakeProjectionFactory::class,
            'options' => [
                'instanceId' => 'newFake'
            ]
        ];
        $this->getObject(ContentRepositoryRegistry::class)->injectSettings($mockSettings);
        $this->getObject(ContentRepositoryRegistry::class)->resetFactoryInstance($this->contentRepository->id);
        $this->setupContentRepositoryDependencies($this->contentRepository->id);

        // todo status doesnt find this projection yet?
        self::assertNull($this->subscriptionStatus('Vendor.Package:NewFakeProjection'));

        // do something that finds new subscriptions, trigger a setup on a specific projection:
        $result = $this->subscriptionEngine->setup(SubscriptionEngineCriteria::create([SubscriptionId::fromString('contentGraph')]));
        self::assertNull($result->errors);

        self::assertEquals(
            ProjectionSubscriptionStatus::create(
                subscriptionId: SubscriptionId::fromString('Vendor.Package:NewFakeProjection'),
                subscriptionStatus: SubscriptionStatus::NEW,
                subscriptionPosition: SequenceNumber::none(),
                subscriptionError: null,
                setupStatus: ProjectionSetupStatus::setupRequired('Set me up')
            ),
            $this->subscriptionStatus('Vendor.Package:NewFakeProjection')
        );

        // setup this projection
        $newFakeProjection->expects(self::once())->method('setUp');
        $result = $this->subscriptionEngine->setup();
        self::assertNull($result->errors);

        $this->expectOkayStatus('Vendor.Package:NewFakeProjection', SubscriptionStatus::BOOTING, SequenceNumber::none());

        $result = $this->subscriptionEngine->boot();
        self::assertNull($result->errors);
        $this->expectOkayStatus('Vendor.Package:NewFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::none());
    }
}
