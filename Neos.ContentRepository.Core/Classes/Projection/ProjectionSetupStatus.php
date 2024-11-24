<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Projection;

/**
 * @api
 */
final readonly class ProjectionSetupStatus
{
    private function __construct(
        public ProjectionSetupStatusType $type,
        public string $details,
    ) {
    }

    public static function ok(): self
    {
        return new self(ProjectionSetupStatusType::OK, '');
    }

    /**
     * @param non-empty-string $details
     */
    public static function error(string $details): self
    {
        return new self(ProjectionSetupStatusType::ERROR, $details);
    }

    /**
     * @param non-empty-string $details
     */
    public static function setupRequired(string $details): self
    {
        return new self(ProjectionSetupStatusType::SETUP_REQUIRED, $details);
    }
}
