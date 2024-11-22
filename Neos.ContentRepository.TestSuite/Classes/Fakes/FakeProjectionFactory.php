<?php

declare(strict_types=1);

namespace Neos\ContentRepository\TestSuite\Fakes;

use Neos\ContentRepository\Core\Factory\SubscriberFactoryDependencies;
use Neos\ContentRepository\Core\Projection\ProjectionFactoryInterface;
use Neos\ContentRepository\Core\Projection\ProjectionInterface;

/**
 * @internal helper to configure custom projection mocks for testing
 */
final class FakeProjectionFactory implements ProjectionFactoryInterface
{
    private static array $projections;

    public function build(
        SubscriberFactoryDependencies $projectionFactoryDependencies,
        array $options,
    ): ProjectionInterface {
        return static::$projections[$options['instanceId']] ?? throw new \RuntimeException('No projection defined for Fake.');
    }

    public static function setProjection(string $instanceId, ProjectionInterface $projection): void
    {
        self::$projections[$instanceId] = $projection;
    }
}
