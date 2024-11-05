<?php

/*
 * This file is part of the Neos.ContentRepository.BehavioralTests package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\ContentRepository\BehavioralTests\Tests\Functional\Feature\WorkspacePublication;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\BehavioralTests\TestSuite\Behavior\GherkinPyStringNodeBasedNodeTypeManagerFactory;
use Neos\ContentRepository\BehavioralTests\TestSuite\Behavior\GherkinTableNodeBasedContentDimensionSourceFactory;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Dimension\ContentDimension;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\Dimension\ContentDimensionSourceInterface;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Feature\RootNodeCreation\Command\CreateRootNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Command\CreateRootWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Command\CreateWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Command\RebaseWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Dto\RebaseErrorHandlingStrategy;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Exception\ContentStreamIsClosed;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\EventStore\Exception\ConcurrencyException;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

/**
 * Parallel test cases for workspace publication
 */
class WorkspaceWritingDuringPublication extends TestCase // we don't use Flows functional test case as it would reset the database afterwards (see FlowEntitiesTrait)
{
    private const REBASE_IS_RUNNING_FLAG_PATH = __DIR__ . '/rebase-is-running-flag';
    private const SETUP_IS_RUNNING_FLAG_PATH = __DIR__ . '/setup-running.lock';
    private const SETUP_IS_DONE_FLAG_PATH = __DIR__ . '/setup-is-done-flag';

    private ?ContentRepository $contentRepository = null;

    private ?ContentRepositoryRegistry $contentRepositoryRegistry = null;

    private static bool $wasContentRepositorySetupCalled = false;

    /** @deprecated please use {@see self::getObject()} instead */
    protected ObjectManagerInterface $objectManager;

    public function setUp(): void
    {
        $this->objectManager = Bootstrap::$staticObjectManager;
        GherkinTableNodeBasedContentDimensionSourceFactory::$contentDimensionsToUse = new class implements ContentDimensionSourceInterface
        {
            public function getDimension(ContentDimensionId $dimensionId): ?ContentDimension
            {
                return null;
            }
            public function getContentDimensionsOrderedByPriority(): array
            {
                return [];
            }
        };

        GherkinPyStringNodeBasedNodeTypeManagerFactory::$nodeTypesToUse = $n = new NodeTypeManager(
            fn (): array => [
                'Neos.ContentRepository:Root' => [],
                'Neos.ContentRepository.Testing:Document' => [
                    'properties' => [
                        'title' => [
                            'type' => 'string'
                        ]
                    ]
                ]
            ]
        );
        $this->contentRepositoryRegistry = $this->objectManager->get(ContentRepositoryRegistry::class);
        $this->contentRepositoryRegistry->resetFactoryInstance(ContentRepositoryId::fromString('test_parallel'));

        if (is_file(self::SETUP_IS_DONE_FLAG_PATH)) {
            $this->contentRepository = $this->contentRepositoryRegistry
                ->get(ContentRepositoryId::fromString('test_parallel'));
            return;
        }

        $runningFile = fopen(self::SETUP_IS_RUNNING_FLAG_PATH, 'w+');
        if( !$runningFile) {
            $this->awaitFileSlowly(self::SETUP_IS_DONE_FLAG_PATH, 60000); // 60s for CR setup
            $this->contentRepository = $this->contentRepositoryRegistry
                ->get(ContentRepositoryId::fromString('test_parallel'));
            // throw new \RuntimeException('failed to open lock file');
        }
        if( !flock($runningFile, LOCK_EX | LOCK_NB)) {
            $this->awaitFileSlowly(self::SETUP_IS_DONE_FLAG_PATH, 60000); // 60s for CR setup
            $this->contentRepository = $this->contentRepositoryRegistry
                ->get(ContentRepositoryId::fromString('test_parallel'));
            // throw new \RuntimeException('failed to lock file');
        }
        ftruncate($runningFile, 0);
        //write something to just help debugging
        fwrite( $runningFile, "Locked\n" . getmypid());
        fflush( $runningFile );




        // touch(self::SETUP_IS_RUNNING_FLAG_PATH);
        // $runningFile = fopen(self::SETUP_IS_RUNNING_FLAG_PATH, 'r+');
        // if (!flock($runningFile, LOCK_EX)) {
        //     $this->awaitFileRemoval(self::SETUP_IS_DONE_FLAG_PATH, 60000); // 60s for CR setup
        //     $this->contentRepository = $this->contentRepositoryRegistry
        //         ->get(ContentRepositoryId::fromString('test_parallel'));
        //     return;
        // }

        $contentRepository = $this->setUpContentRepository(ContentRepositoryId::fromString('test_parallel'));


        $origin = OriginDimensionSpacePoint::createWithoutDimensions();
        $contentRepository->handle(CreateRootWorkspace::create(
            WorkspaceName::forLive(),
            ContentStreamId::fromString('live-cs-id')
        ));
        $contentRepository->handle(CreateRootNodeAggregateWithNode::create(
            WorkspaceName::forLive(),
            NodeAggregateId::fromString('lady-eleonode-rootford'),
            NodeTypeName::fromString(NodeTypeName::ROOT_NODE_TYPE_NAME)
        ));
        $contentRepository->handle(CreateNodeAggregateWithNode::create(
            WorkspaceName::forLive(),
            NodeAggregateId::fromString('nody-mc-nodeface'),
            NodeTypeName::fromString('Neos.ContentRepository.Testing:Document'),
            $origin,
            NodeAggregateId::fromString('lady-eleonode-rootford'),
            initialPropertyValues: PropertyValuesToWrite::fromArray([
                'title' => 'title'
            ])
        ));
        $contentRepository->handle(CreateWorkspace::create(
            WorkspaceName::fromString('user-test'),
            WorkspaceName::forLive(),
            ContentStreamId::fromString('user-cs-id')
        ));
        for ($i = 0; $i <= 5000; $i++) {
            $contentRepository->handle(CreateNodeAggregateWithNode::create(
                WorkspaceName::forLive(),
                NodeAggregateId::fromString('nody-mc-nodeface-' . $i),
                NodeTypeName::fromString('Neos.ContentRepository.Testing:Document'),
                $origin,
                NodeAggregateId::fromString('lady-eleonode-rootford'),
                initialPropertyValues: PropertyValuesToWrite::fromArray([
                    'title' => 'title'
                ])
            ));
            // give the database lock some time to recover
            // usleep(5000);
        }
        $this->contentRepository = $contentRepository;

        touch(self::SETUP_IS_DONE_FLAG_PATH);


        if( !flock($runningFile, LOCK_UN) ) {
            throw new \RuntimeException('failed to release lock file');
        }
        ftruncate($runningFile, 0);
        // write something to just help debugging
        fwrite( $runningFile, "Unlocked\n");
        fflush( $runningFile );


        // fclose($runningFile);
        // unlink(self::SETUP_IS_RUNNING_FLAG_PATH);
    }

    /**
     * @test
     * @group parallel
     */
    public function whileAWorkspaceIsBeingRebased(): void
    {
        touch(self::REBASE_IS_RUNNING_FLAG_PATH);
        $workspaceName = WorkspaceName::fromString('user-test');
        $exception = null;
        try {
            // force rebase
            $this->contentRepository->handle(RebaseWorkspace::create(
                $workspaceName,
            )->withRebasedContentStreamId(ContentStreamId::create())
            ->withErrorHandlingStrategy(RebaseErrorHandlingStrategy::STRATEGY_FORCE));
        } catch (\RuntimeException $runtimeException) {
            $exception = $runtimeException;
        }
        unlink(self::REBASE_IS_RUNNING_FLAG_PATH);
        Assert::assertNull($exception);
    }

    /**
     * @test
     * @group parallel
     */
    public function thenConcurrentCommandsLeadToAnException(): void
    {
        $this->awaitFile(self::REBASE_IS_RUNNING_FLAG_PATH);
        // give the CR some time to close the content stream
        /// usleep(10000);
        $origin = OriginDimensionSpacePoint::createWithoutDimensions();
        $actualException = null;
        try {
            $this->contentRepository->handle(SetNodeProperties::create(
                WorkspaceName::fromString('user-test'),
                NodeAggregateId::fromString('nody-mc-nodeface'),
                $origin,
                PropertyValuesToWrite::fromArray([
                    'title' => 'title47b'
                ])
            ));
        } catch (\Exception $thrownException) {
            $actualException = $thrownException;
        }

        unlink(self::SETUP_IS_DONE_FLAG_PATH);

        Assert::assertThat($actualException, self::logicalOr(
            self::isInstanceOf(ContentStreamIsClosed::class),
            self::isInstanceOf(ConcurrencyException::class),
        ));
    }

    private function awaitFile(string $filename): void
    {
        $waiting = 0;
        while (!is_file($filename)) {
            usleep(1000);
            $waiting++;
            clearstatcache(true, $filename);
            if ($waiting > 60000) {
                throw new \Exception('timeout while waiting on file ' . $filename);
            }
        }
    }

    private function awaitFileSlowly(string $filename, int $maximumCycles = 2000): void
    {
        $waiting = 0;
        while (!is_file($filename)) {
            usleep(10000);
            $waiting++;
            clearstatcache(true, $filename);
            if ($waiting > $maximumCycles) {
                throw new \Exception('timeout while waiting on file ' . $filename);
            }
        }
    }

    protected function setUpContentRepository(
        ContentRepositoryId $contentRepositoryId
    ): ContentRepository {

        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        // Performance optimization: only run the setup once
        // if (!self::$wasContentRepositorySetupCalled) {
            $contentRepository->setUp();
        //     self::$wasContentRepositorySetupCalled = true;
        // }

        $connection = $this->getObject(Connection::class);

        // reset events and projections
        $eventTableName = sprintf('cr_%s_events', $contentRepositoryId->value);
        $connection->executeStatement('TRUNCATE ' . $eventTableName);
        $contentRepository->resetProjectionStates();

        // $this->contentRepositoryRegistry->resetFactoryInstance($contentRepositoryId);

        return $contentRepository;
    }

    /**
     * @template T of object
     * @param class-string<T> $className
     *
     * @return T
     */
    final protected function getObject(string $className): object
    {
        return $this->objectManager->get($className);
    }


}
