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

namespace Neos\ContentRepository\BehavioralTests\Tests\Parallel;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Abstract parallel test cases
 */
abstract class AbstractParallelTestCase extends TestCase // we don't use Flows functional test case as it would reset the database afterwards (see FlowEntitiesTrait)
{
    private const LOGGING_PATH = __DIR__ . '/log.txt';

    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    protected ObjectManagerInterface $objectManager;

    public function setUp(): void
    {
        $this->objectManager = Bootstrap::$staticObjectManager;
        $this->contentRepositoryRegistry = $this->objectManager->get(ContentRepositoryRegistry::class);
    }

    final protected function awaitFile(string $filename): void
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

    final protected function awaitFileRemoval(string $filename): void
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

    final protected function setUpContentRepository(
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

    final protected function log(string $message): void
    {
        file_put_contents(self::LOGGING_PATH, substr($this::class, strrpos($this::class, '\\') + 1) . ': ' . getmypid() . ': ' .  $message . PHP_EOL, FILE_APPEND);
    }
}
