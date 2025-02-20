<?php

/*
 * This file is part of the Neos.ContentRepository package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Feature\WorkspaceRebase\Event;

use Neos\ContentRepository\Core\EventStore\EventInterface;
use Neos\ContentRepository\Core\Feature\Common\EmbedsWorkspaceName;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;

/**
 * @deprecated This event will never be emitted, and it is ignored in the core projections. This implementation is just kept for backwards-compatibility
 * instead an exception is thrown: {@see \Neos\ContentRepository\Core\Feature\WorkspaceRebase\Exception\WorkspaceRebaseFailed}
 * @internal
 */
final readonly class WorkspaceRebaseFailed implements EventInterface, EmbedsWorkspaceName
{
    /**
     * @param array<int,array<string,mixed>> $errors
     */
    public function __construct(
        public WorkspaceName $workspaceName,
        /**
         * The content stream on which we could not apply the source content stream's commands -- i.e. the "failed"
         * state.
         */
        public ContentStreamId $candidateContentStreamId,
        /**
         * The content stream which we tried to rebase
         */
        public ContentStreamId $sourceContentStreamId,
        public array $errors
    ) {
    }

    public function getWorkspaceName(): WorkspaceName
    {
        return $this->workspaceName;
    }

    public static function fromArray(array $values): self
    {
        return new self(
            WorkspaceName::fromString($values['workspaceName']),
            ContentStreamId::fromString($values['candidateContentStreamId']),
            ContentStreamId::fromString($values['sourceContentStreamId']),
            $values['errors']
        );
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
