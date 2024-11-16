<?php

declare(strict_types=1);

namespace Neos\ContentRepository\BehavioralTests\TestSuite\Behavior;

use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphReadModelInterface;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\TestSuite\Behavior\Features\Bootstrap\Helpers\TestingAuthProvider;
use Neos\ContentRepositoryRegistry\Factory\AuthProvider\AuthProviderFactoryInterface;

final class TestingAuthProviderFactory implements AuthProviderFactoryInterface
{
    public function build(ContentRepositoryId $contentRepositoryId, ContentGraphReadModelInterface $contentGraphReadModel): TestingAuthProvider
    {
        return new TestingAuthProvider();
    }
}
