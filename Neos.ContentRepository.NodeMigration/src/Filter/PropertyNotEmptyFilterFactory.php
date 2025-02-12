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
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;

/**
 * Filter nodes having the given property and its value not empty.
 */
class PropertyNotEmptyFilterFactory implements FilterFactoryInterface
{
    /**
     * @param array<string,string> $settings
     */
    public function build(array $settings, ContentRepository $contentRepository): NodeAggregateBasedFilterInterface|NodeBasedFilterInterface
    {
        $propertyName = $settings['propertyName'];

        return new class ($propertyName) implements NodeBasedFilterInterface {
            public function __construct(
                /**
                 * The property name to be checked for non-empty value
                 */
                private readonly string $propertyName
            ) {
            }

            public function matches(Node $node): bool
            {
                if ($node->hasProperty($this->propertyName)) {
                    $propertyValue = $node->getProperty($this->propertyName);
                    return !empty($propertyValue);
                }
                return false;
            }
        };
    }
}
