<?php

declare(strict_types=1);

namespace Neos\ContentRepository\TestSuite\Fakes;

use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\User\UserIdProviderInterface;
use Neos\ContentRepositoryRegistry\Factory\UserIdProvider\UserIdProviderFactoryInterface;

final class FakeUserIdProviderFactory implements UserIdProviderFactoryInterface
{
    /**
     * @param array<string,mixed> $options
     */
    public function build(ContentRepositoryId $contentRepositoryId, array $options): UserIdProviderInterface
    {
        return new FakeUserIdProvider();
    }
}
