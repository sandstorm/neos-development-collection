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

namespace Neos\ContentRepository\Core\Projection\ContentGraph;

use Neos\ContentRepository\Core\Feature\SubtreeTagging\Dto\SubtreeTag;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Dto\SubtreeTags;

/**
 * The visibility constraints define a context in which the content graph is accessed.
 *
 * For example: In the `frontend` context, nodes with the `disabled` tag are excluded. In the `backend` context {@see self::withoutRestrictions()} they are included
 *
 * @api
 */
final readonly class VisibilityConstraints implements \JsonSerializable
{
    /**
     * @param SubtreeTags $tagConstraints A set of {@see SubtreeTag} instances that will be _excluded_ from the results of any content graph query
     */
    private function __construct(
        public SubtreeTags $tagConstraints,
    ) {
    }

    /**
     * A subgraph without constraints for finding all nodes without filtering
     *
     * Nodes for example with tag disabled will be findable
     */
    public static function createEmpty(): self
    {
        return new self(SubtreeTags::createEmpty());
    }

    /**
     * @param SubtreeTags $tagConstraints A set of {@see SubtreeTag} instances that will be _excluded_ from the results of any content graph query
     */
    public static function fromTagConstraints(SubtreeTags $tagConstraints): self
    {
        return new self($tagConstraints);
    }

    public function getHash(): string
    {
        return md5(implode('|', $this->tagConstraints->toStringArray()));
    }

    public static function default(): VisibilityConstraints
    {
        return new self(SubtreeTags::fromArray([SubtreeTag::disabled()]));
    }

    public function withAddedSubtreeTag(SubtreeTag $subtreeTag): self
    {
        return new self($this->tagConstraints->merge(SubtreeTags::fromArray([$subtreeTag])));
    }

    /**
     * A subgraph without constraints for finding all nodes without filtering
     *
     * Only for Neos.Neos context!
     *
     * Nodes for example with tag disabled will be findable but not soft removed nodes
     *
     * For backwards compatibility to previous betas this method does not do what it promises.
     * The classic Neos backend use-case previously used this method to be able to find also disabled nodes.
     * Now with the introduction of soft removed tags, empty constraints will cause nodes
     * to show up that were previously non-existent. Thus, this factory restricts 'removed' after all.
     *
     * @deprecated with Neos 9 beta 19
     */
    public static function withoutRestrictions(): self
    {
        return new self(SubtreeTags::fromStrings('removed'));
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
