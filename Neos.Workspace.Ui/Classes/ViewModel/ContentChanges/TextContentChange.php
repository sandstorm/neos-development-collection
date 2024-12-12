<?php

/*
 * This file is part of the Neos.Workspace.Ui package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\Workspace\Ui\ViewModel\ContentChanges;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class TextContentChange extends ContentChange
{
    /** @param array<int|string,mixed> $diff */
    public function __construct(
        public array $diff,
    ) {
    }
}
