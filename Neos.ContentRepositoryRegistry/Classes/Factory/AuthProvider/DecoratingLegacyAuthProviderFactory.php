<?php

declare(strict_types=1);

namespace Neos\ContentRepositoryRegistry\Factory\AuthProvider;

use Neos\ContentRepository\Core\Feature\Security\AuthProviderInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphReadModelInterface;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepositoryRegistry\Factory\AuthProvider\AuthProviderFactoryInterface as LegacyAuthProviderFactoryInterface;

/**
 * @deprecated {@see LegacyAuthProviderFactoryInterface}
 */
final readonly class DecoratingLegacyAuthProviderFactory implements \Neos\ContentRepository\Core\Factory\AuthProviderFactoryInterface
{
    public function __construct(
        private LegacyAuthProviderFactoryInterface $legacyAuthProviderFactory
    ) {
    }

    public function build(
        ContentRepositoryId $contentRepositoryId,
        ContentGraphReadModelInterface $contentGraphReadModel
    ): AuthProviderInterface {
        return $this->legacyAuthProviderFactory->build($contentRepositoryId, $contentGraphReadModel);
    }
}
