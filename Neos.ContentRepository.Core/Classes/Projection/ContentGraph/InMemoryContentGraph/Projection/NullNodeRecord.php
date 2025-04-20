<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Projection\ContentGraph\InMemoryContentGraph\Projection;

/**
 * The null node record to be used in \SplObjectStorages instead of null
 *
 * @internal
 */
final class NullNodeRecord
{
    private static ?self $instance = null;

    private static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function create(): self
    {
        return self::instance();
    }
}
