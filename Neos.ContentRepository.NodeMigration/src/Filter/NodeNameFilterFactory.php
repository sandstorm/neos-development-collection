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

namespace Neos\ContentRepository\NodeMigration\Filter;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeAggregate;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;

/**
 * Filter nodes by node name.
 */
class NodeNameFilterFactory implements FilterFactoryInterface
{
    /**
     * @param array<string,string> $settings
     */
    public function build(array $settings, ContentRepository $contentRepository): NodeAggregateBasedFilterInterface|NodeBasedFilterInterface
    {
        $nodeName = NodeName::fromString($settings['nodeName']);

        return new class ($nodeName) implements NodeAggregateBasedFilterInterface {
            public function __construct(
                /**
                 * The node name to match on.
                 */
                private readonly NodeName $nodeName
            ) {
            }

            public function matches(NodeAggregate $nodeAggregate): bool
            {
                if (!$nodeAggregate->nodeName) {
                    return false;
                }
                return $this->nodeName->equals($nodeAggregate->nodeName);
            }
        };
    }
}
