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
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
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
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
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
    private const LOGGING_PATH = __DIR__ . '/log.txt';
    private const SETUP_LOCK_PATH = __DIR__ . '/setup-lock';
    private const REBASE_IS_RUNNING_FLAG_PATH = __DIR__ . '/rebase-is-running-flag';

    private ?ContentRepository $contentRepository = null;

    private ?ContentRepositoryRegistry $contentRepositoryRegistry = null;

    protected ObjectManagerInterface $objectManager;

    public function setUp(): void
    {
        $this->log('------ process started ------');
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

        $setupLockResource = fopen(self::SETUP_LOCK_PATH, 'w+');

        $exclusiveNonBlockingLockResult = flock($setupLockResource, LOCK_EX | LOCK_NB);
        if ($exclusiveNonBlockingLockResult === false) {
            $this->log('waiting for setup');
            $this->awaitSharedLock($setupLockResource);
            $this->contentRepository = $this->contentRepositoryRegistry
                ->get(ContentRepositoryId::fromString('test_parallel'));
            $this->log('wait for setup finished');
            return;
        }

        $this->log('setup started');
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
                'title' => 'title-original'
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
            // TODO? Why? usleep(5000);
        }
        $this->contentRepository = $contentRepository;

        if (!flock($setupLockResource, LOCK_UN)) {
            throw new \RuntimeException('failed to release setup lock');
        }

        $this->log('setup finished');
    }

    /**
     * @test
     * @group parallel
     */
    public function whileAWorkspaceIsBeingRebased(): void
    {
        $workspaceName = WorkspaceName::fromString('user-test');
        $this->log('rebase started');

        touch(self::REBASE_IS_RUNNING_FLAG_PATH);

        try {
            $this->contentRepository->handle(
                RebaseWorkspace::create($workspaceName)
                    ->withRebasedContentStreamId(ContentStreamId::create())
                    ->withErrorHandlingStrategy(RebaseErrorHandlingStrategy::STRATEGY_FORCE));
        } finally {
            unlink(self::REBASE_IS_RUNNING_FLAG_PATH);
        }

        $this->log('rebase finished');
        Assert::assertTrue(true, 'No exception was thrown ;)');
    }

    /**
     * @test
     * @group parallel
     */
    public function thenConcurrentCommandsLeadToAnException(): void
    {
        if (!is_file(self::REBASE_IS_RUNNING_FLAG_PATH)) {
            $this->log('write waiting');

            $this->awaitFile(self::REBASE_IS_RUNNING_FLAG_PATH);
            // If write is the process that does the (slowish) setup, and then waits for the rebase to start,
            // We give the CR some time to close the content stream
            // TODO find another way than to randomly wait!!!
            // The problem is, if we dont sleep it happens often that the modification works only then the rebase is startet _really_
            // Doing the modification several times in hope that the second one fails will likely just stop the rebase thread as it cannot close
            usleep(10000);
        }

        $this->log('write started');

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

        $this->log('write finished');

        $node = $this->contentRepository->getContentGraph(WorkspaceName::fromString('user-test'))
            ->getSubgraph(DimensionSpacePoint::createWithoutDimensions(), VisibilityConstraints::withoutRestrictions())
            ->findNodeById(NodeAggregateId::fromString('nody-mc-nodeface'));

        if ($actualException === null) {
            Assert::fail(sprintf('No exception was thrown. Mutated Node: %s', json_encode($node?->properties->serialized())));
        }

        Assert::assertThat($actualException, self::logicalOr(
            self::isInstanceOf(ContentStreamIsClosed::class),
            self::isInstanceOf(ConcurrencyException::class),
        ));

        Assert::assertSame('title-original', $node?->getProperty('title'));
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

    private function awaitSharedLock($resource, int $maximumCycles = 2000): void
    {
        $waiting = 0;
        while (!flock($resource, LOCK_SH)) {
            usleep(10000);
            $waiting++;
            if ($waiting > $maximumCycles) {
                throw new \Exception('timeout while waiting on shared lock');
            }
        }
    }

    protected function setUpContentRepository(
        ContentRepositoryId $contentRepositoryId
    ): ContentRepository {
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $contentRepository->setUp();

        $connection = $this->objectManager->get(Connection::class);

        // reset events and projections
        $eventTableName = sprintf('cr_%s_events', $contentRepositoryId->value);
        $connection->executeStatement('TRUNCATE ' . $eventTableName);
        $contentRepository->resetProjectionStates();

        return $contentRepository;
    }

    private function log(string $message): void
    {
        file_put_contents(self::LOGGING_PATH, getmypid() . ': ' .  $message . PHP_EOL, FILE_APPEND);
    }
}
