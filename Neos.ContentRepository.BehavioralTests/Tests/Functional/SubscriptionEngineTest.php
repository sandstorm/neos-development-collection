<?php

declare(strict_types=1);

namespace Neos\ContentRepository\BehavioralTests\Tests\Functional;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\BehavioralTests\TestSuite\DebugEventProjection;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryDependencies;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryInterface;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceInterface;
use Neos\ContentRepository\Core\Feature\ContentStreamCreation\Event\ContentStreamWasCreated;
use Neos\ContentRepository\Core\Feature\ContentStreamEventStreamName;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Command\CreateRootWorkspace;
use Neos\ContentRepository\Core\Projection\ProjectionInterface;
use Neos\ContentRepository\Core\Projection\ProjectionStateInterface;
use Neos\ContentRepository\Core\Projection\ProjectionStatus;
use Neos\ContentRepository\Core\Service\SubscriptionService;
use Neos\ContentRepository\Core\Service\SubscriptionServiceFactory;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Core\Subscription\Engine\Error;
use Neos\ContentRepository\Core\Subscription\Engine\Errors;
use Neos\ContentRepository\Core\Subscription\Engine\ProcessedResult;
use Neos\ContentRepository\Core\Subscription\Engine\SubscriptionEngine;
use Neos\ContentRepository\Core\Subscription\Engine\SubscriptionEngineCriteria;
use Neos\ContentRepository\Core\Subscription\Store\SubscriptionCriteria;
use Neos\ContentRepository\Core\Subscription\SubscriptionAndProjectionStatus;
use Neos\ContentRepository\Core\Subscription\SubscriptionAndProjectionStatuses;
use Neos\ContentRepository\Core\Subscription\SubscriptionError;
use Neos\ContentRepository\Core\Subscription\SubscriptionId;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\ContentRepository\TestSuite\Fakes\FakeContentDimensionSourceFactory;
use Neos\ContentRepository\TestSuite\Fakes\FakeNodeTypeManagerFactory;
use Neos\ContentRepository\TestSuite\Fakes\FakeProjectionFactory;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Model\Event;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\EventStream\ExpectedVersion;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub\Exception as WillThrowException;
use PHPUnit\Framework\TestCase;

final class SubscriptionEngineTest extends TestCase // we don't use Flows functional test case as it would reset the database afterwards
{
    private ContentRepository $contentRepository;

    private SubscriptionService $subscriptionService;

    private SubscriptionEngine $subscriptionEngine;

    private EventStoreInterface $eventStore;

    private ObjectManagerInterface $objectManager;

    private ProjectionInterface&MockObject $fakeProjection;

    private DebugEventProjection $secondFakeProjection;

    public function setUp(): void
    {
        $this->objectManager = Bootstrap::$staticObjectManager;
        $contentRepositoryId = ContentRepositoryId::fromString('t_subscription');

        $this->resetDatabase(
            $this->getObject(Connection::class),
            $contentRepositoryId,
            keepSchema: true
        );

        $this->fakeProjection = $this->getMockBuilder(ProjectionInterface::class)->disableAutoReturnValueGeneration()->getMock();
        $this->fakeProjection->method('getState')->willReturn(new class implements ProjectionStateInterface {});

        FakeProjectionFactory::setProjection(
            'default',
            $this->fakeProjection
        );

        $this->secondFakeProjection = new DebugEventProjection(
            'cr_t_subscription_debug_projection',
            $this->getObject(Connection::class)
        );

        FakeProjectionFactory::setProjection(
            'second',
            $this->secondFakeProjection
        );

        FakeNodeTypeManagerFactory::setConfiguration([]);
        FakeContentDimensionSourceFactory::setWithoutDimensions();

        $this->getObject(ContentRepositoryRegistry::class)->resetFactoryInstance($contentRepositoryId);
        $originalSettings = $this->getObject(ConfigurationManager::class)->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, 'Neos.ContentRepositoryRegistry');
        $this->getObject(ContentRepositoryRegistry::class)->injectSettings($originalSettings);

        $this->setupContentRepositoryDependencies($contentRepositoryId);
    }

    public function setupContentRepositoryDependencies(ContentRepositoryId $contentRepositoryId)
    {
        $this->contentRepository = $this->getObject(ContentRepositoryRegistry::class)->get(
            $contentRepositoryId
        );

        $this->subscriptionService = $this->getObject(ContentRepositoryRegistry::class)->buildService($contentRepositoryId, new SubscriptionServiceFactory());

        $subscriptionEngineAndEventStoreAccessor = new class implements ContentRepositoryServiceFactoryInterface {
            public EventStoreInterface|null $eventStore;
            public SubscriptionEngine|null $subscriptionEngine;
            public function build(ContentRepositoryServiceFactoryDependencies $serviceFactoryDependencies): ContentRepositoryServiceInterface
            {
                $this->eventStore = $serviceFactoryDependencies->eventStore;
                $this->subscriptionEngine = $serviceFactoryDependencies->subscriptionEngine;
                return new class implements ContentRepositoryServiceInterface
                {
                };
            }
        };
        $this->getObject(ContentRepositoryRegistry::class)->buildService($contentRepositoryId, $subscriptionEngineAndEventStoreAccessor);
        $this->eventStore = $subscriptionEngineAndEventStoreAccessor->eventStore;
        $this->subscriptionEngine = $subscriptionEngineAndEventStoreAccessor->subscriptionEngine;
    }


    /** @test */
    public function statusOnEmptyDatabase()
    {
        // fully drop the tables so that status has to recover if the subscriptions table is not there
        $this->resetDatabase(
            $this->getObject(Connection::class),
            $this->contentRepository->id,
            keepSchema: false
        );

        $this->fakeProjection->expects(self::once())->method('status')->willReturn(ProjectionStatus::setupRequired('fake needs setup.'));

        $actualStatuses = $this->subscriptionService->subscriptionEngine->subscriptionStatuses();

        $expected = SubscriptionAndProjectionStatuses::fromArray([
            SubscriptionAndProjectionStatus::create(
                subscriptionId: SubscriptionId::fromString('contentGraph'),
                subscriptionStatus: SubscriptionStatus::NEW,
                subscriptionPosition: SequenceNumber::none(),
                subscriptionError: null,
                projectionStatus: ProjectionStatus::setupRequired(''),
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
                projectionStatus: ProjectionStatus::ok(),
            ),
        ]);

        self::assertEquals($expected, $actualStatuses);
    }

    /** @test */
    public function setupOnEmptyDatabase()
    {
        $this->subscriptionService->setupEventStore();

        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->subscriptionService->subscriptionEngine->setup();

        $this->fakeProjection->expects(self::exactly(2))->method('status')->willReturn(ProjectionStatus::ok());
        $actualStatuses = $this->subscriptionService->subscriptionEngine->subscriptionStatuses();

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
                subscriptionStatus: SubscriptionStatus::BOOTING,
                subscriptionPosition: SequenceNumber::none(),
                subscriptionError: null,
                projectionStatus: ProjectionStatus::ok(),
            ),
            SubscriptionAndProjectionStatus::create(
                subscriptionId: SubscriptionId::fromString('Vendor.Package:SecondFakeProjection'),
                subscriptionStatus: SubscriptionStatus::BOOTING,
                subscriptionPosition: SequenceNumber::none(),
                subscriptionError: null,
                projectionStatus: ProjectionStatus::ok(),
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
    public function setupProjectionsAndCatchup()
    {
        $this->subscriptionService->setupEventStore();

        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->subscriptionService->subscriptionEngine->setup();

        $result = $this->subscriptionService->subscriptionEngine->boot();
        self::assertEquals(ProcessedResult::success(0), $result);
        $this->fakeProjection->expects(self::any())->method('status')->willReturn(ProjectionStatus::ok());
        $this->expectOkayStatus('contentGraph', SubscriptionStatus::ACTIVE, SequenceNumber::none());
        $this->expectOkayStatus('Vendor.Package:FakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::none());
        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::none());

        // commit an event
        $this->commitExampleContentStreamEvent();

        // subsequent catchup setup'd does not change the position
        $result = $this->subscriptionService->subscriptionEngine->boot();
        self::assertEquals(ProcessedResult::success(0), $result);
        $this->expectOkayStatus('contentGraph', SubscriptionStatus::ACTIVE, SequenceNumber::none());
        $this->expectOkayStatus('Vendor.Package:FakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::none());
        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::none());

        // catchup active does apply the commited event
        $this->fakeProjection->expects(self::once())->method('apply')->with(self::isInstanceOf(ContentStreamWasCreated::class));
        $result = $this->subscriptionService->subscriptionEngine->catchUpActive();
        self::assertEquals(ProcessedResult::success(1), $result);

        $this->expectOkayStatus('contentGraph', SubscriptionStatus::ACTIVE, SequenceNumber::fromInteger(1));
        $this->expectOkayStatus('Vendor.Package:FakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::fromInteger(1));
        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::fromInteger(1));
    }

    /** @test */
    public function existingEventStoreEventsAreCaughtUpOnBoot()
    {
        $this->eventStore->setup();
        // commit an event
        $this->commitExampleContentStreamEvent();

        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->subscriptionService->subscriptionEngine->setup();
        $this->fakeProjection->expects(self::any())->method('status')->willReturn(ProjectionStatus::ok());

        $this->expectOkayStatus('contentGraph', SubscriptionStatus::BOOTING, SequenceNumber::none());
        $this->expectOkayStatus('Vendor.Package:FakeProjection', SubscriptionStatus::BOOTING, SequenceNumber::none());

        $this->fakeProjection->expects(self::once())->method('apply')->with(self::isInstanceOf(ContentStreamWasCreated::class));
        $this->subscriptionService->subscriptionEngine->boot();

        $this->expectOkayStatus('contentGraph', SubscriptionStatus::ACTIVE, SequenceNumber::fromInteger(1));
        $this->expectOkayStatus('Vendor.Package:FakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::fromInteger(1));

        // catchup is a noop because there are no unhandled events
        $result = $this->subscriptionService->subscriptionEngine->catchUpActive();
        self::assertEquals(ProcessedResult::success(0), $result);
    }

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
        $this->subscriptionService->subscriptionEngine->setup();
        $this->subscriptionService->subscriptionEngine->boot();

        // commit an event
        $this->commitExampleContentStreamEvent();

        $this->fakeProjection->expects(self::once())->method('apply')->with(self::isInstanceOf(ContentStreamWasCreated::class));

        $this->secondFakeProjection->sabotageAfterApply($exception = new \RuntimeException('This projection is kaputt.'));

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
        self::assertTrue($result->hasFailed());

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
                projectionStatus: null // no calculate-able at this point!
            ),
            $this->subscriptionStatus('Vendor.Package:FakeProjection')
        );
    }

    /** @test */
    public function newProjectionIsFoundConfigurationIsAdded()
    {
        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->fakeProjection->expects(self::any())->method('status')->willReturn(ProjectionStatus::ok());

        $this->subscriptionService->setupEventStore();
        $this->subscriptionService->subscriptionEngine->setup();

        $result = $this->subscriptionService->subscriptionEngine->boot();
        self::assertEquals(ProcessedResult::success(0), $result);

        self::assertNull($this->subscriptionStatus('Vendor.Package:NewFakeProjection'));
        $this->expectOkayStatus('Vendor.Package:FakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::none());

        $newFakeProjection = $this->getMockBuilder(ProjectionInterface::class)->disableAutoReturnValueGeneration()->getMock();
        $newFakeProjection->method('getState')->willReturn(new class implements ProjectionStateInterface {});
        $newFakeProjection->expects(self::exactly(3))->method('status')->willReturnOnConsecutiveCalls(
            ProjectionStatus::setupRequired('Set me up'),
            ProjectionStatus::ok(),
            ProjectionStatus::ok(),
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

        // do something that finds new subscriptions
        $result = $this->subscriptionEngine->catchUpActive();
        self::assertNull($result->errors);

        self::assertEquals(
            SubscriptionAndProjectionStatus::create(
                subscriptionId: SubscriptionId::fromString('Vendor.Package:NewFakeProjection'),
                subscriptionStatus: SubscriptionStatus::NEW,
                subscriptionPosition: SequenceNumber::none(),
                subscriptionError: null,
                projectionStatus: ProjectionStatus::setupRequired('Set me up')
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

    /** @test */
    public function filteringSetup()
    {
        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->fakeProjection->expects(self::once())->method('status')->willReturn(ProjectionStatus::ok());

        $this->subscriptionService->setupEventStore();

        $filter = SubscriptionEngineCriteria::create([SubscriptionId::fromString('Vendor.Package:FakeProjection')]);

        $result = $this->subscriptionService->subscriptionEngine->setup($filter);
        self::assertNull($result->errors);

        $this->expectOkayStatus('Vendor.Package:FakeProjection', SubscriptionStatus::BOOTING, SequenceNumber::none());

        self::assertEquals(
            SubscriptionAndProjectionStatus::create(
                subscriptionId: SubscriptionId::fromString('Vendor.Package:SecondFakeProjection'),
                subscriptionStatus: SubscriptionStatus::NEW,
                subscriptionPosition: SequenceNumber::none(),
                subscriptionError: null,
                projectionStatus: ProjectionStatus::ok()
            ),
            $this->subscriptionStatus('Vendor.Package:SecondFakeProjection')
        );
    }

    /** @test */
    public function filteringCatchUpBoot()
    {
        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->fakeProjection->expects(self::any())->method('status')->willReturn(ProjectionStatus::ok());

        $this->subscriptionService->setupEventStore();

        $result = $this->subscriptionService->subscriptionEngine->setup();
        self::assertNull($result->errors);


        $this->expectOkayStatus('Vendor.Package:FakeProjection', SubscriptionStatus::BOOTING, SequenceNumber::none());
        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::BOOTING, SequenceNumber::none());

        $filter = SubscriptionEngineCriteria::create([SubscriptionId::fromString('Vendor.Package:FakeProjection')]);

        $result = $this->subscriptionEngine->boot($filter);
        self::assertNull($result->errors);

        $this->expectOkayStatus('Vendor.Package:FakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::none());
        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::BOOTING, SequenceNumber::none());
    }

    /** @test */
    public function filteringCatchUpActive()
    {
        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->fakeProjection->expects(self::any())->method('status')->willReturn(ProjectionStatus::ok());

        $this->subscriptionService->setupEventStore();

        $result = $this->subscriptionService->subscriptionEngine->setup();
        self::assertNull($result->errors);
        $result = $this->subscriptionEngine->boot();
        self::assertNull($result->errors);

        $this->expectOkayStatus('Vendor.Package:FakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::none());
        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::none());

        // commit an event:
        $this->commitExampleContentStreamEvent();

        $this->fakeProjection->expects(self::once())->method('apply');

        $filter = SubscriptionEngineCriteria::create([SubscriptionId::fromString('Vendor.Package:FakeProjection')]);
        $result = $this->subscriptionEngine->catchUpActive($filter);
        self::assertNull($result->errors);

        self::assertEmpty(
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );

        $this->expectOkayStatus('Vendor.Package:FakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::fromInteger(1));
        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::none());
    }

    /** @test */
    public function filteringReset()
    {
        $this->fakeProjection->expects(self::once())->method('setUp');
        $this->fakeProjection->expects(self::any())->method('status')->willReturn(ProjectionStatus::ok());

        $this->subscriptionService->setupEventStore();

        $result = $this->subscriptionService->subscriptionEngine->setup();
        self::assertNull($result->errors);
        self::assertEmpty(
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );

        // commit an event:
        $this->commitExampleContentStreamEvent();

        $this->fakeProjection->expects(self::once())->method('apply');
        $this->fakeProjection->expects(self::once())->method('resetState');
        $result = $this->subscriptionEngine->boot();
        self::assertNull($result->errors);

        $this->expectOkayStatus('Vendor.Package:FakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::fromInteger(1));
        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::fromInteger(1));

        $filter = SubscriptionEngineCriteria::create([SubscriptionId::fromString('Vendor.Package:FakeProjection')]);
        $result = $this->subscriptionEngine->reset($filter);
        self::assertNull($result->errors);

        $this->expectOkayStatus('Vendor.Package:FakeProjection', SubscriptionStatus::BOOTING, SequenceNumber::none());
        $this->expectOkayStatus('Vendor.Package:SecondFakeProjection', SubscriptionStatus::ACTIVE, SequenceNumber::fromInteger(1));

        self::assertEquals(
            [SequenceNumber::fromInteger(1)],
            $this->secondFakeProjection->getState()->findAppliedSequenceNumbers()
        );
    }

    private function resetDatabase(Connection $connection, ContentRepositoryId $contentRepositoryId, bool $keepSchema): void
    {
        $connection->prepare('SET FOREIGN_KEY_CHECKS = 0;')->executeStatement();
        foreach ($connection->createSchemaManager()->listTableNames() as $tableNames) {
            if (!str_starts_with($tableNames, sprintf('cr_%s_', $contentRepositoryId->value))) {
                // speedup deletion, only delete current cr
                continue;
            }
            if ($keepSchema) {
                // truncate is faster
                $sql = 'TRUNCATE TABLE ' . $tableNames;
            } else {
                $sql = 'DROP TABLE ' . $tableNames;
            }
            $connection->prepare($sql)->executeStatement();
        }
        $connection->prepare('SET FOREIGN_KEY_CHECKS = 1;')->executeStatement();
    }

    private function subscriptionStatus(string $subscriptionId): ?SubscriptionAndProjectionStatus
    {
        return $this->subscriptionService->subscriptionEngine->subscriptionStatuses(SubscriptionCriteria::create(ids: [SubscriptionId::fromString($subscriptionId)]))->first();
    }

    private function commitExampleContentStreamEvent(): void
    {
        $this->eventStore->commit(
            ContentStreamEventStreamName::fromContentStreamId(ContentStreamId::fromString('cs-id'))->getEventStreamName(),
            new Event(
                Event\EventId::create(),
                Event\EventType::fromString('ContentStreamWasCreated'),
                Event\EventData::fromString(json_encode(['contentStreamId' => 'cs-id']))
            ),
            ExpectedVersion::NO_STREAM()
        );
    }

    private function expectOkayStatus($subscriptionId, SubscriptionStatus $status, SequenceNumber $sequenceNumber): void
    {
        $actual = $this->subscriptionStatus($subscriptionId);
        self::assertEquals(
            SubscriptionAndProjectionStatus::create(
                subscriptionId: SubscriptionId::fromString($subscriptionId),
                subscriptionStatus: $status,
                subscriptionPosition: $sequenceNumber,
                subscriptionError: null,
                projectionStatus: ProjectionStatus::ok(),
            ),
            $actual
        );
    }

    /**
     * @template T of object
     * @param class-string<T> $className
     *
     * @return T
     */
    private function getObject(string $className): object
    {
        return $this->objectManager->get($className);
    }
}
