<?php

declare(strict_types=1);

namespace Neos\ContentRepository\BehavioralTests\TestSuite\Behavior;

use Neos\ContentRepository\Core\Feature\Security\AuthProviderInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphReadModelInterface;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\TestSuite\Behavior\Features\Bootstrap\Helpers\FakeAuthProvider;
use Neos\ContentRepositoryRegistry\Factory\AuthProvider\AuthProviderFactoryInterface;

final class FakeAuthProviderFactory implements AuthProviderFactoryInterface
{
    public function build(ContentRepositoryId $contentRepositoryId, ContentGraphReadModelInterface $contentGraphReadModel): AuthProviderInterface
    {
        return new FakeAuthProvider();
    }
}
