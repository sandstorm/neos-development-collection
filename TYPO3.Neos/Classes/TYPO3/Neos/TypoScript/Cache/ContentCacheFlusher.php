<?php
namespace TYPO3\Neos\TypoScript\Cache;

/*
 * This file is part of the TYPO3.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Log\SystemLoggerInterface;
use TYPO3\Media\Domain\Model\AssetInterface;
use TYPO3\Media\Domain\Service\AssetService;
use TYPO3\Neos\Domain\Model\Dto\AssetUsageInNodeProperties;
use TYPO3\TYPO3CR\Domain\Model\NodeInterface;
use TYPO3\TYPO3CR\Domain\Model\NodeType;
use TYPO3\TYPO3CR\Domain\Service\NodeTypeManager;
use TYPO3\TypoScript\Core\Cache\ContentCache;

/**
 * This service flushes TypoScript content caches triggered by node changes.
 *
 * The method registerNodeChange() is triggered by a signal which is configured in the Package class of the TYPO3.Neos
 * package (this package). Information on changed nodes is collected by this method and the respective TypoScript content
 * cache entries are flushed in one operation during Flow's shutdown procedure.
 *
 * @Flow\Scope("singleton")
 */
class ContentCacheFlusher
{
    /**
     * @Flow\Inject
     * @var ContentCache
     */
    protected $contentCache;

    /**
     * @Flow\Inject
     * @var SystemLoggerInterface
     */
    protected $systemLogger;

    /**
     * @var array
     */
    protected $tagsToFlush = array();

    /**
     * @Flow\Inject
     * @var AssetService
     */
    protected $assetService;

    /**
     * @Flow\Inject()
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * Register a node change for a later cache flush. This method is triggered by a signal sent via TYPO3CR's Node
     * model or the Neos Publishing Service.
     *
     * @param NodeInterface $node The node which has changed in some way
     * @return void
     */
    public function registerNodeChange(NodeInterface $node)
    {
        $this->tagsToFlush[ContentCache::TAG_EVERYTHING] = 'which were tagged with "Everything".';

        $this->registerChangeOnNodeType($node->getNodeType()->getName(), $node->getIdentifier());
        $this->registerChangeOnNodeIdentifier($node->getIdentifier());

        $originalNode = $node;
        while ($node->getDepth() > 1) {
            $node = $node->getParent();
            // Workaround for issue #56566 in TYPO3.TYPO3CR
            if ($node === null) {
                break;
            }
            $tagName = 'DescendantOf_' . $node->getIdentifier();
            $this->tagsToFlush[$tagName] = sprintf('which were tagged with "%s" because node "%s" has changed.', $tagName, $originalNode->getPath());
        }
    }

    /**
     * @param string $nodeIdentifier
     */
    public function registerChangeOnNodeIdentifier($nodeIdentifier)
    {
        $this->tagsToFlush[ContentCache::TAG_EVERYTHING] = 'which were tagged with "Everything".';
        $this->tagsToFlush['Node_' . $nodeIdentifier] = sprintf('which were tagged with "Node_%s" because that identifier has changed.', $nodeIdentifier);

        // Note, as we don't have a node here we cannot go up the structure.
        $tagName = 'DescendantOf_' . $nodeIdentifier;
        $this->tagsToFlush[$tagName] = sprintf('which were tagged with "%s" because node "%s" has changed.', $tagName, $nodeIdentifier);
    }

    /**
     * @param string $nodeTypeName
     * @param string $referenceNodeIdentifier
     */
    public function registerChangeOnNodeType($nodeTypeName, $referenceNodeIdentifier = null)
    {
        $this->tagsToFlush[ContentCache::TAG_EVERYTHING] = 'which were tagged with "Everything".';

        $nodeTypesToFlush = $this->getAllImplementedNodeTypeNames($this->nodeTypeManager->getNodeType($nodeTypeName));
        foreach ($nodeTypesToFlush as $nodeTypeNameToFlush) {
            $this->tagsToFlush['NodeType_' . $nodeTypeNameToFlush] = sprintf('which were tagged with "NodeType_%s" because node "%s" has changed and was of type "%s".', $nodeTypeNameToFlush, ($referenceNodeIdentifier ? $referenceNodeIdentifier : ''), $nodeTypeName);
        }
    }

    /**
     * Deprecated. Please use ContentCacheFlush::registerAssetChange
     *
     * @deprecated
     * @param AssetInterface $asset
     * @return void
     */
    public function registerAssetResourceChange(AssetInterface $asset)
    {
        $this->registerAssetChange($asset);
    }

    /**
     * Fetches possible usages of the asset and registers nodes that use the asset as changed.
     *
     * @param AssetInterface $asset
     * @return void
     */
    public function registerAssetChange(AssetInterface $asset)
    {
        if (!$asset->isInUse()) {
            return;
        }

        foreach ($this->assetService->getUsageReferences($asset) as $reference) {
            if (!$reference instanceof AssetUsageInNodeProperties) {
                continue;
            }

            $this->registerChangeOnNodeIdentifier($reference->getNodeIdentifier());
            $this->registerChangeOnNodeType($reference->getNodeTypeName(), $reference->getNodeIdentifier());
        }
    }

    /**
     * Flush caches according to the previously registered node changes.
     *
     * @return void
     */
    public function shutdownObject()
    {
        if ($this->tagsToFlush !== array()) {
            foreach ($this->tagsToFlush as $tag => $logMessage) {
                $affectedEntries = $this->contentCache->flushByTag($tag);
                if ($affectedEntries > 0) {
                    $this->systemLogger->log(sprintf('Content cache: Removed %s entries %s', $affectedEntries, $logMessage), LOG_DEBUG);
                }
            }
        }
    }

    /**
     * @param NodeType $nodeType
     * @return array<string>
     */
    protected function getAllImplementedNodeTypeNames(NodeType $nodeType)
    {
        $self = $this;
        $types = array_reduce($nodeType->getDeclaredSuperTypes(), function (array $types, NodeType $superType) use ($self) {
            return array_merge($types, $self->getAllImplementedNodeTypeNames($superType));
        }, [$nodeType->getName()]);

        $types = array_unique($types);
        return $types;
    }
}