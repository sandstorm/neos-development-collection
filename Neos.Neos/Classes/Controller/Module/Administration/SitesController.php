<?php

/*
 * This file is part of the Neos.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\Neos\Controller\Module\Administration;

use Neos\ContentRepository\Core\Feature\NodeRenaming\Command\ChangeNodeAggregateName;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeAggregate;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeNameIsAlreadyCovered;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeTypeNotFound;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Workspace\Workspace;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\ContentRepositoryRegistry\Exception\ContentRepositoryNotFoundException;
use Neos\Error\Messages\Message;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Package;
use Neos\Flow\Package\PackageManager;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Flow\Session\SessionInterface;
use Neos\Media\Domain\Repository\AssetCollectionRepository;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Neos\Controller\Module\ModuleTranslationTrait;
use Neos\Neos\Domain\Exception\SiteNodeNameIsAlreadyInUseByAnotherSite;
use Neos\Neos\Domain\Exception\SiteNodeTypeIsInvalid;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\Domain\Service\SiteImportService;
use Neos\Neos\Domain\Service\SiteService;
use Neos\Neos\Domain\Service\UserService;
use Neos\Utility\Files;

/**
 * The Neos Sites Management module controller
 */
class SitesController extends AbstractModuleController
{
    use ModuleTranslationTrait;

    /**
     * @Flow\Inject
     * @var DomainRepository
     */
    protected $domainRepository;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var AssetCollectionRepository
     */
    protected $assetCollectionRepository;

    /**
     * @Flow\Inject
     * @var PackageManager
     */
    protected $packageManager;

    /**
     * @Flow\Inject
     * @var SiteService
     */
    protected $siteService;

    /**
     * @Flow\Inject
     * @var SessionInterface
     */
    protected $session;

    /**
     * This is not 100% correct, but it is as good as we can get it to work right now
     * It works when the created site's name will use the configuration "*" which by default uses the default preset.
     *
     * @Flow\InjectConfiguration("sitePresets.default.contentRepository")
     */
    protected $defaultContentRepositoryForNewSites;

    /**
     * @Flow\Inject
     * @var SecurityContext
     */
    protected $securityContext;

    /**
     * @Flow\Inject
     * @var SiteImportService
     */
    protected $siteImportService;

    #[Flow\Inject]
    protected UserService $domainUserService;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * @return void
     */
    public function indexAction()
    {
        $sitePackagesAndSites = [];
        foreach (
            $this->packageManager->getFilteredPackages(
                'available',
                'neos-site'
            ) as $sitePackageKey => $sitePackage
        ) {
            /** @var Package $sitePackage */
            $sitePackagesAndSites[strtolower(str_replace('.', '_', $sitePackageKey))] = [
                'package' => $sitePackage,
                'packageKey' => $sitePackage->getPackageKey()
            ];
        }
        $sites = $this->siteRepository->findAll();
        foreach ($sites as $site) {
            $siteResourcePackageKey = strtolower(str_replace('.', '_', $site->getSiteResourcesPackageKey()));
            if (!isset($sitePackagesAndSites[$siteResourcePackageKey])) {
                $sitePackagesAndSites[$siteResourcePackageKey] = ['packageKey' => $site->getSiteResourcesPackageKey()];
            }
            if (!isset($sitePackagesAndSites[$siteResourcePackageKey]['sites'])) {
                $sitePackagesAndSites[$siteResourcePackageKey]['sites'] = [];
            }
            $sitePackagesAndSites[$siteResourcePackageKey]['sites'][] = $site;
        }
        $this->view->assignMultiple([
            'sitePackagesAndSites' => $sitePackagesAndSites,
            'multipleSites' => count($sites) > 1
        ]);
    }

    /**
     * A edit view for a site and its settings.
     *
     * @param Site $site Site to view
     * @Flow\IgnoreValidation("$site")
     * @return void
     */
    public function editAction(Site $site)
    {
        try {
            $sitePackage = $this->packageManager->getPackage($site->getSiteResourcesPackageKey());
        } catch (\Exception $e) {
            $this->addFlashMessage(
                $this->getModuleLabel(
                    'sites.sitePackageNotFound.body',
                    [htmlspecialchars($site->getSiteResourcesPackageKey())]
                ),
                $this->getModuleLabel('sites.sitePackageNotFound.title'),
                Message::SEVERITY_ERROR
            );
        }

        $this->view->assignMultiple([
            'site' => $site,
            'sitePackage' => $sitePackage ?? [],
            'domains' => $this->domainRepository->findBySite($site),
            'assetCollections' => $this->assetCollectionRepository->findAll()
        ]);
    }

    /**
     * Update a site
     *
     * @param Site $site A site to update
     * @param string $newSiteNodeName A new site node name
     * @return void
     * @Flow\Validate(argumentName="$site", type="UniqueEntity")
     * @Flow\Validate(argumentName="$newSiteNodeName", type="NotEmpty")
     * @Flow\Validate(argumentName="$newSiteNodeName", type="StringLength", options={ "minimum"=1, "maximum"=250 })
     * @Flow\Validate(argumentName="$newSiteNodeName", type="Neos.Neos:NodeName")
     */
    public function updateSiteAction(Site $site, $newSiteNodeName)
    {
        if ($site->getNodeName()->value !== $newSiteNodeName) {
            $contentRepository = $this->contentRepositoryRegistry->get($site->getConfiguration()->contentRepositoryId);

            $liveWorkspace = $contentRepository->findWorkspaceByName(WorkspaceName::forLive());
            if (!$liveWorkspace instanceof Workspace) {
                throw new \InvalidArgumentException(
                    'Cannot update a site without the live workspace being present.',
                    1651958443
                );
            }

            $sitesNode = $contentRepository->getContentGraph($liveWorkspace->workspaceName)->findRootNodeAggregateByType(
                NodeTypeNameFactory::forSites()
            );
            if ($sitesNode === null) {
                throw new \InvalidArgumentException(
                    'Cannot update a site without the sites note being present.',
                    1651958452
                );
            }

            $currentUser = $this->domainUserService->getCurrentUser();
            if (is_null($currentUser)) {
                throw new \InvalidArgumentException(
                    'Cannot update a site without a current user',
                    1651958722
                );
            }

            foreach ($contentRepository->findWorkspaces() as $workspace) {
                $siteNodeAggregate = $contentRepository->getContentGraph($workspace->workspaceName)->findChildNodeAggregateByName(
                    $sitesNode->nodeAggregateId,
                    $site->getNodeName()->toNodeName()
                );
                if ($siteNodeAggregate instanceof NodeAggregate) {
                    $contentRepository->handle(ChangeNodeAggregateName::create(
                        $workspace->workspaceName,
                        $siteNodeAggregate->nodeAggregateId,
                        NodeName::fromString($newSiteNodeName),
                    ));
                }
            }

            $site->setNodeName($newSiteNodeName);
        }

        $this->siteRepository->update($site);

        $this->addFlashMessage(
            $this->getModuleLabel('sites.update.body', [htmlspecialchars($site->getName())]),
            $this->getModuleLabel('sites.update.title'),
            Message::SEVERITY_OK,
            [],
            1412371798
        );
        $this->redirect('index');
    }

    /**
     * Create a new site form
     */
    public function newSiteAction(): void
    {
        try {
            $contentRepositoryId = ContentRepositoryId::fromString($this->defaultContentRepositoryForNewSites ?? '');
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException('The default content repository for new sites configured in "Neos.Neos.sitePresets.default.contentRepository" is not valid.', 1736946907, $e);
        }

        try {
            $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        } catch (ContentRepositoryNotFoundException $e) {
            throw new \RuntimeException(sprintf('The default content repository for new sites "%s" could not be instantiated.', $contentRepositoryId->value), 1736946907);
        }

        $documentNodeTypes = $contentRepository->getNodeTypeManager()->getSubNodeTypes(NodeTypeNameFactory::forSite(), false);

        $sitePackages = $this->packageManager->getFilteredPackages('available', 'neos-site');

        $this->view->assignMultiple([
            'defaultContentRepositoryForNewSites' => $contentRepositoryId->value,
            // The live workspace has to be empty prior to importing, that's why we disable the functionality
            'canImportFromPackage' => $this->siteRepository->findFirst() === null,
            'sitePackages' => $sitePackages,
            'documentNodeTypes' => $documentNodeTypes
        ]);
    }

    /**
     * Import a site from site package.
     *
     * @param string $packageKey Package from where the import will come
     * @Flow\Validate(argumentName="$packageKey", type="\Neos\Neos\Validation\Validator\PackageKeyValidator")
     * @return void
     */
    public function importSiteAction($packageKey)
    {
        try {
            $contentRepositoryId = ContentRepositoryId::fromString($this->defaultContentRepositoryForNewSites ?? '');
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException('The default content repository for new sites configured in "Neos.Neos.sitePresets.default.contentRepository" is not valid.', 1736946907, $e);
        }

        $package = $this->packageManager->getPackage($packageKey);
        $path = Files::concatenatePaths([$package->getPackagePath(), 'Resources/Private/Content']);

        // CreateRootWorkspace was denied: Creation of root workspaces is currently only allowed with disabled authorization checks
        $this->securityContext->withoutAuthorizationChecks(fn () => $this->siteImportService->importFromPath(
            $contentRepositoryId,
            $path,
            fn () => null,
            fn () => null
        ));

        $this->addFlashMessage(
            $this->getModuleLabel('sites.theSiteHasBeenImported.body'),
            '',
            Message::SEVERITY_OK,
            [],
            1412372266
        );

        $this->redirect('index');
    }

    /**
     * Create a new empty site.
     *
     * @param string $packageKey Package Name to create
     * @param string $siteName Site Name to create
     * @param string $nodeType NodeType name for the root node to create
     * @Flow\Validate(argumentName="$packageKey", type="\Neos\Neos\Validation\Validator\PackageKeyValidator")
     * @return void
     */
    public function createSiteNodeAction($packageKey, $siteName, $nodeType)
    {
        try {
            // CreateRootWorkspace was denied: Creation of root workspaces is currently only allowed with disabled authorization checks
            $site = null;
            $this->securityContext->withoutAuthorizationChecks(function () use (&$site, $packageKey, $siteName, $nodeType) {
                $site = $this->siteService->createSite($packageKey, $siteName, $nodeType);
            });
        } catch (NodeTypeNotFound $exception) {
            $this->addFlashMessage(
                $this->getModuleLabel('sites.siteCreationError.givenNodeTypeNotFound.body', [$nodeType]),
                $this->getModuleLabel('sites.siteCreationError.givenNodeTypeNotFound.title'),
                Message::SEVERITY_ERROR,
                [],
                1412372375
            );
            $this->redirect('createSiteNode');
        } catch (SiteNodeTypeIsInvalid $exception) {
            $this->addFlashMessage(
                $this->getModuleLabel(
                    'sites.siteCreationError.givenNodeTypeNotBasedOnSuperType.body',
                    [$nodeType, NodeTypeNameFactory::NAME_SITE]
                ),
                $this->getModuleLabel('sites.siteCreationError.givenNodeTypeNotBasedOnSuperType.title'),
                Message::SEVERITY_ERROR,
                [],
                1412372375
            );
            $this->redirect('createSiteNode');
        } catch (SiteNodeNameIsAlreadyInUseByAnotherSite | NodeNameIsAlreadyCovered $exception) {
            $this->addFlashMessage(
                $this->getModuleLabel('sites.SiteCreationError.siteWithSiteNodeNameAlreadyExists.body', [$siteName]),
                $this->getModuleLabel('sites.SiteCreationError.siteWithSiteNodeNameAlreadyExists.title'),
                Message::SEVERITY_ERROR,
                [],
                1412372375
            );
            $this->redirect('createSiteNode');
        }

        $this->addFlashMessage(
            $this->getModuleLabel(
                'sites.successfullyCreatedSite.body',
                [$site->getName(), $site->getNodeName()->value, $nodeType, $packageKey]
            ),
            '',
            Message::SEVERITY_OK,
            [],
            1412372266
        );
        $this->redirect('index');
    }

    /**
     * Delete a site.
     *
     * @param Site $site Site to delete
     * @Flow\IgnoreValidation("$site")
     * @return void
     */
    public function deleteSiteAction(Site $site)
    {
        $this->siteService->pruneSite($site);
        $this->addFlashMessage(
            $this->getModuleLabel('sites.siteDeleted.body', [htmlspecialchars($site->getName())]),
            $this->getModuleLabel('sites.siteDeleted.title'),
            Message::SEVERITY_OK,
            [],
            1412372689
        );
        $this->redirect('index');
    }

    /**
     * Activates a site
     *
     * @param Site $site Site to activate
     * @return void
     */
    public function activateSiteAction(Site $site)
    {
        $site->setState($site::STATE_ONLINE);
        $this->siteRepository->update($site);
        $this->addFlashMessage(
            $this->getModuleLabel('sites.siteActivated.body', [htmlspecialchars($site->getName())]),
            $this->getModuleLabel('sites.siteActivated.title'),
            Message::SEVERITY_OK,
            [],
            1412372881
        );
        $this->redirect('index');
    }

    /**
     * Deactivates a site
     *
     * @param Site $site Site to deactivate
     * @return void
     */
    public function deactivateSiteAction(Site $site)
    {
        $site->setState($site::STATE_OFFLINE);
        $this->siteRepository->update($site);
        $this->addFlashMessage(
            $this->getModuleLabel('sites.siteDeactivated.body', [htmlspecialchars($site->getName())]),
            $this->getModuleLabel('sites.siteDeactivated.title'),
            Message::SEVERITY_OK,
            [],
            1412372975
        );
        $this->redirect('index');
    }

    /**
     * Edit a domain
     *
     * @param Domain $domain Domain to edit
     * @Flow\IgnoreValidation("$domain")
     * @return void
     */
    public function editDomainAction(Domain $domain)
    {
        $this->view->assignMultiple([
            'domain' => $domain,
            'schemes' => [null => '', 'http' => 'HTTP', 'https' => 'HTTPS']
        ]);
    }

    /**
     * Update a domain
     *
     * @param Domain $domain Domain to update
     * @Flow\Validate(argumentName="$domain", type="UniqueEntity")
     * @return void
     */
    public function updateDomainAction(Domain $domain)
    {
        $this->domainRepository->update($domain);
        $this->addFlashMessage(
            $this->getModuleLabel('sites.domainUpdated.body', [htmlspecialchars($domain->__toString())]),
            $this->getModuleLabel('sites.domainUpdated.title'),
            Message::SEVERITY_OK,
            [],
            1412373069
        );
        $this->redirect('edit', null, null, ['site' => $domain->getSite()]);
    }

    /**
     * The create a new domain action.
     *
     * @param Domain $domain
     * @param Site $site
     * @Flow\IgnoreValidation("$domain")
     * @return void
     */
    public function newDomainAction(Domain $domain = null, Site $site = null)
    {
        $this->view->assignMultiple([
            'domain' => $domain,
            'site' => $site,
            'schemes' => [null => '', 'http' => 'HTTP', 'https' => 'HTTPS']
        ]);
    }

    /**
     * Create a domain
     *
     * @param Domain $domain Domain to create
     * @Flow\Validate(argumentName="$domain", type="UniqueEntity")
     * @return void
     */
    public function createDomainAction(Domain $domain)
    {
        $this->domainRepository->add($domain);
        $this->addFlashMessage(
            $this->getModuleLabel('sites.domainCreated.body', [htmlspecialchars($domain->__toString())]),
            $this->getModuleLabel('sites.domainCreated.title'),
            Message::SEVERITY_OK,
            [],
            1412373192
        );
        $this->redirect('edit', null, null, ['site' => $domain->getSite()]);
    }

    /**
     * Deletes a domain attached to a site
     *
     * @param Domain $domain A domain to delete
     * @Flow\IgnoreValidation("$domain")
     * @return void
     */
    public function deleteDomainAction(Domain $domain)
    {
        $site = $domain->getSite();
        if ($site->getPrimaryDomain() === $domain) {
            $site->setPrimaryDomain(null);
            $this->siteRepository->update($site);
        }
        $this->domainRepository->remove($domain);
        $this->addFlashMessage(
            $this->getModuleLabel('sites.domainDeleted.body', [htmlspecialchars($domain->__toString())]),
            $this->getModuleLabel('sites.domainDeleted.title'),
            Message::SEVERITY_OK,
            [],
            1412373310
        );
        $this->redirect('edit', null, null, ['site' => $site]);
    }

    /**
     * Activates a domain
     *
     * @param Domain $domain Domain to activate
     * @Flow\IgnoreValidation("$domain")
     * @return void
     */
    public function activateDomainAction(Domain $domain)
    {
        $domain->setActive(true);
        $this->domainRepository->update($domain);
        $this->addFlashMessage(
            $this->getModuleLabel('sites.domainActivated.body', [htmlspecialchars($domain->__toString())]),
            $this->getModuleLabel('sites.domainActivated.title'),
            Message::SEVERITY_OK,
            [],
            1412373539
        );
        $this->redirect('edit', null, null, ['site' => $domain->getSite()]);
    }

    /**
     * Deactivates a domain
     *
     * @param Domain $domain Domain to deactivate
     * @Flow\IgnoreValidation("$domain")
     * @return void
     */
    public function deactivateDomainAction(Domain $domain)
    {
        $domain->setActive(false);
        $this->domainRepository->update($domain);
        $this->addFlashMessage(
            $this->getModuleLabel('sites.domainDeactivated.body', [htmlspecialchars($domain->__toString())]),
            $this->getModuleLabel('sites.domainDeactivated.title'),
            Message::SEVERITY_OK,
            [],
            1412373425
        );
        $this->redirect('edit', null, null, ['site' => $domain->getSite()]);
    }
}
