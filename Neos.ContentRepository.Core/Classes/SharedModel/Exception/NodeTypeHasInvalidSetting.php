<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\SharedModel\Exception;

/*
 * This file is part of the Neos.ContentRepository package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Core\NodeType\NodeTypeName;

/**
 * The exception to be thrown if a NodeType has a wrong configuration for a setting
 *
 * @api because exception is thrown during invariant checks on command execution
 */
final class NodeTypeHasInvalidSetting extends \DomainException
{
    public static function butItIsRequired(NodeTypeName $nodeTypeName, string $setting): self
    {
        return new self('Given node type "' . $nodeTypeName->value . '" has no valid setting for "' . $setting . '"', 1630061720);
    }
}
