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

namespace Neos\Workspace\Ui\Controller;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Dto\SubtreeTag;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Exception\WorkspaceAlreadyExists;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Exception\WorkspaceRebaseFailed;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindAncestorNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\Nodes;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\Workspace;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Diff\Diff;
use Neos\Diff\Renderer\Html\HtmlArrayRenderer;
use Neos\Error\Messages\Message;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Translator;
use Neos\Flow\Mvc\Exception\StopActionException;
use Neos\Flow\Package\PackageManager;
use Neos\Flow\Property\PropertyMapper;
use Neos\Flow\Security\Context;
use Neos\Flow\Security\Policy\PolicyService;
use Neos\Fusion\View\FusionView;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\ImageInterface;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Neos\Domain\Model\User;
use Neos\Neos\Domain\Model\WorkspaceClassification;
use Neos\Neos\Domain\Model\WorkspaceDescription;
use Neos\Neos\Domain\Model\WorkspaceRoleAssignments;
use Neos\Neos\Domain\Model\WorkspaceTitle;
use Neos\Neos\Domain\NodeLabel\NodeLabelGeneratorInterface;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\Domain\Service\UserService;
use Neos\Neos\Domain\Service\WorkspacePublishingService;
use Neos\Neos\Domain\Service\WorkspaceService;
use Neos\Neos\FrontendRouting\NodeUriBuilderFactory;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use Neos\Neos\PendingChangesProjection\ChangeFinder;
use Neos\Neos\PendingChangesProjection\Changes;
use Neos\Neos\Security\Authorization\ContentRepositoryAuthorizationService;
use Neos\Neos\Utility\NodeTypeWithFallbackProvider;
use Neos\Workspace\Ui\ViewModel\ChangeItem;
use Neos\Workspace\Ui\ViewModel\ContentChangeItem;
use Neos\Workspace\Ui\ViewModel\ContentChangeItems;
use Neos\Workspace\Ui\ViewModel\ContentChangeProperties;
use Neos\Workspace\Ui\ViewModel\ContentChanges\AssetContentChange;
use Neos\Workspace\Ui\ViewModel\ContentChanges\DateTimeContentChange;
use Neos\Workspace\Ui\ViewModel\ContentChanges\ImageContentChange;
use Neos\Workspace\Ui\ViewModel\ContentChanges\TagContentChange;
use Neos\Workspace\Ui\ViewModel\ContentChanges\TextContentChange;
use Neos\Workspace\Ui\ViewModel\DocumentChangeItem;
use Neos\Workspace\Ui\ViewModel\DocumentItem;
use Neos\Workspace\Ui\ViewModel\EditWorkspaceFormData;
use Neos\Workspace\Ui\ViewModel\PendingChanges;
use Neos\Workspace\Ui\ViewModel\WorkspaceListItem;
use Neos\Workspace\Ui\ViewModel\WorkspaceListItems;

/**
 * The Neos Workspace module controller
 */
#[Flow\Scope('singleton')]
class WorkspaceController extends AbstractModuleController
{
    use NodeTypeWithFallbackProvider;

    protected $defaultViewObjectName = FusionView::class;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected NodeUriBuilderFactory $nodeUriBuilderFactory;

    #[Flow\Inject]
    protected SiteRepository $siteRepository;

    #[Flow\Inject]
    protected PropertyMapper $propertyMapper;

    #[Flow\Inject]
    protected Context $securityContext;

    #[Flow\Inject]
    protected UserService $userService;

    #[Flow\Inject]
    protected PackageManager $packageManager;

    #[Flow\Inject]
    protected WorkspacePublishingService $workspacePublishingService;

    #[Flow\Inject]
    protected WorkspaceService $workspaceService;

    #[Flow\Inject]
    protected NodeLabelGeneratorInterface $nodeLabelGenerator;

    #[Flow\Inject]
    protected Translator $translator;

    #[Flow\Inject]
    protected PolicyService $policyService;

    #[Flow\Inject]
    protected ContentRepositoryAuthorizationService $authorizationService;

    /**
     * Display a list of unpublished content
     */
    public function indexAction(string $sortBy = 'title', bool $sortAscending = true): void
    {
        $currentUser = $this->userService->getCurrentUser();
        if ($currentUser === null) {
            throw new \RuntimeException('No user authenticated', 1718308216);
        }

        $contentRepositoryIds = $this->contentRepositoryRegistry->getContentRepositoryIds();
        $numberOfContentRepositories = $contentRepositoryIds->count();
        if ($numberOfContentRepositories === 0) {
            throw new \RuntimeException('No content repository configured', 1718296290);
        }
        if ($this->request->hasArgument('contentRepositoryId')) {
            $contentRepositoryIdArgument = $this->request->getArgument('contentRepositoryId');
            assert(is_string($contentRepositoryIdArgument));
            $contentRepositoryId = ContentRepositoryId::fromString($contentRepositoryIdArgument);
        } else {
            $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        }
        $this->view->assign('contentRepositoryIds', $contentRepositoryIds);
        $this->view->assign('contentRepositoryId', $contentRepositoryId->value);
        $this->view->assign('displayContentRepositorySelector', $numberOfContentRepositories > 1);

        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $workspaceListItems = $this->getWorkspaceListItems($contentRepository, $currentUser);

        if ($sortBy === 'title') {
            $workspaceListItems = $workspaceListItems->sortByTitle($sortAscending);
        }

        $this->view->assignMultiple([
            // todo remove userWorkspaceName field and add distinction to $workspaceListItems as $workspaceListItems->userWorkspace and $workspaceListItems->otherWorkspaces or something.
            'userWorkspaceName' => $this->workspaceService->getPersonalWorkspaceForUser($contentRepositoryId, $currentUser->getId())->workspaceName->value,
            'workspaceListItems' => $workspaceListItems,
            'flashMessages' => $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush(),
            'sortAscending' => $sortAscending,
            'sortBy' => $sortBy,
        ]);
    }

    public function reviewAction(WorkspaceName $workspace): void
    {
        $currentUser = $this->userService->getCurrentUser();
        if ($currentUser === null) {
            throw new \RuntimeException('No user authenticated', 1720371024);
        }
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);

        $workspaceObj = $contentRepository->findWorkspaceByName($workspace);
        if (is_null($workspaceObj)) {
            $title = WorkspaceTitle::fromString($workspace->value);
            $this->addFlashMessage(
                $this->getModuleLabel('workspaces.workspaceDoesNotExist', [$title->value]),
                '',
                Message::SEVERITY_ERROR
            );
            $this->forward('index');
        }

        $workspacePermissions = $this->authorizationService->getWorkspacePermissions($contentRepositoryId, $workspace, $this->securityContext->getRoles(), $currentUser->getId());
        if(!$workspacePermissions->read){
            $this->addFlashMessage(
                $this->getModuleLabel('workspaces.changes.noPermissionToReadWorkspace'),
                '',
                Message::SEVERITY_ERROR
            );
            $this->forward('index');
        }
        $workspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $workspace);
        $baseWorkspaceMetadata = null;
        $baseWorkspacePermissions = null;
        $baseWorkspace = $workspaceObj->baseWorkspaceName !== null
            ? $contentRepository->findWorkspaceByName($workspaceObj->baseWorkspaceName)
            : null;
        if ($baseWorkspace !== null) {
            $baseWorkspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $baseWorkspace->workspaceName);
            $baseWorkspacePermissions = $this->authorizationService->getWorkspacePermissions($contentRepositoryId, $baseWorkspace->workspaceName, $this->securityContext->getRoles(), $currentUser->getId());
        }
        $this->view->assignMultiple([
            'selectedWorkspaceName' => $workspaceObj->workspaceName->value,
            'selectedWorkspaceLabel' => $workspaceMetadata->title->value,
            'baseWorkspaceName' => $workspaceObj->baseWorkspaceName,
            'baseWorkspaceLabel' => $baseWorkspaceMetadata?->title->value,
            'canPublishToBaseWorkspace' => $baseWorkspacePermissions?->write ?? false,
            'canPublishToWorkspace' => $workspacePermissions->write,
            'siteChanges' => $this->computeSiteChanges($workspaceObj, $contentRepository),
            'contentDimensions' => $contentRepository->getContentDimensionSource()->getContentDimensionsOrderedByPriority(),
            'flashMessages' => $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush(),
        ]);
    }

    public function newAction(): void
    {
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);

        $this->view->assign('baseWorkspaceOptions', $this->prepareBaseWorkspaceOptions($contentRepository));
    }

    public function createAction(
        WorkspaceTitle $title,
        WorkspaceName $baseWorkspace,
        WorkspaceDescription $description,
        string $visibility = 'shared',
    ): void {
        $currentUser = $this->userService->getCurrentUser();
        if ($currentUser === null) {
            throw new \RuntimeException('No user authenticated', 1718303756);
        }

        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $workspaceName = $this->workspaceService->getUniqueWorkspaceName($contentRepositoryId, $title->value);

        try {
            $assignments = $visibility === 'shared' ?
                WorkspaceRoleAssignments::createForSharedWorkspace($currentUser->getId()) :
                WorkspaceRoleAssignments::createForPrivateWorkspace($currentUser->getId());
            $this->workspaceService->createSharedWorkspace(
                $contentRepositoryId,
                $workspaceName,
                $title,
                $description,
                $baseWorkspace,
                $assignments
            );
        } catch (WorkspaceAlreadyExists $exception) {
            $this->addFlashMessage(
                $this->getModuleLabel('workspaces.workspaceWithThisTitleAlreadyExists'),
                '',
                Message::SEVERITY_WARNING
            );
            $this->throwStatus(400, 'Workspace with this title already exists');
        } catch (\Exception $exception) {
            $this->addFlashMessage(
                $exception->getMessage(),
                $this->getModuleLabel('workspaces.workspaceCouldNotBeCreated'),
                Message::SEVERITY_ERROR
            );
            $this->throwStatus(500, 'Workspace could not be created');
        }
        $this->addFlashMessage($this->getModuleLabel('workspaces.workspaceHasBeenCreated', [$title->value]));
        $this->forward('index');
    }

    /**
     * Edit a workspace
     *
     * Renders /Resource/Private/Fusion/Views/Edit.fusion
     */
    public function editAction(WorkspaceName $workspaceName): void
    {
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())
            ->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);

        $workspace = $contentRepository->findWorkspaceByName($workspaceName);
        $title = WorkspaceTitle::fromString($workspaceName->value);
        if (is_null($workspace)) {
            $this->addFlashMessage(
                $this->getModuleLabel('workspaces.workspaceDoesNotExist', [$title->value]),
                '',
                Message::SEVERITY_ERROR
            );
            $this->throwStatus(404, 'Workspace does not exist');
        }

        if ($workspace->isRootWorkspace()) {
            throw new \RuntimeException(sprintf('Workspace %s does not have a base-workspace.', $workspace->workspaceName->value), 1734019485);
        }

        $workspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $workspace->workspaceName);

        $editWorkspaceDto = new EditWorkspaceFormData(
            workspaceName: $workspace->workspaceName,
            workspaceTitle: $workspaceMetadata->title,
            workspaceDescription: $workspaceMetadata->description,
            workspaceHasChanges: $this->computePendingChanges($workspace, $contentRepository)->total > 0,
            baseWorkspaceName: $workspace->baseWorkspaceName,
            baseWorkspaceOptions: $this->prepareBaseWorkspaceOptions($contentRepository, $workspaceName),
        );

        $this->view->assign('editWorkspaceFormData', $editWorkspaceDto);
    }

    /**
     * Update a workspace
     *
     * @Flow\Validate(argumentName="title", type="\Neos\Flow\Validation\Validator\NotEmptyValidator")
     * @param WorkspaceTitle $title Human friendly title of the workspace, for example "Christmas Campaign"
     * @param WorkspaceDescription $description A description explaining the purpose of the new workspace
     */
    public function updateAction(
        WorkspaceName $workspaceName,
        WorkspaceTitle $title,
        WorkspaceDescription $description,
        WorkspaceName $baseWorkspace,
    ): void {
        $currentUser = $this->userService->getCurrentUser();
        if ($currentUser === null) {
            throw new \RuntimeException('No user is authenticated', 1729505338);
        }

        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);

        if ($title->value === '') {
            $title = WorkspaceTitle::fromString($workspaceName->value);
        }

        $workspace = $contentRepository->findWorkspaceByName($workspaceName);

        $userCanManageWorkspace = $this->authorizationService->getWorkspacePermissions($contentRepositoryId, $workspaceName, $this->securityContext->getRoles(), $this->userService->getCurrentUser()?->getId())->manage;
        if (!$userCanManageWorkspace) {
            $this->throwStatus(403);
        }

        if ($workspace === null) {
            $this->addFlashMessage(
                $this->getModuleLabel('workspaces.workspaceDoesNotExist'),
                '',
                Message::SEVERITY_ERROR
            );
            $this->throwStatus(404, 'Workspace does not exist');
        }

        // Update Metadata
        $this->workspaceService->setWorkspaceTitle(
            $contentRepositoryId,
            $workspaceName,
            $title,
        );
        $this->workspaceService->setWorkspaceDescription(
            $contentRepositoryId,
            $workspaceName,
            $description,
        );

        // Update Base Workspace
        $this->workspacePublishingService->changeBaseWorkspace(
            $contentRepositoryId,
            $workspaceName,
            $baseWorkspace
        );

        $this->addFlashMessage(
            $this->getModuleLabel(
                'workspaces.workspaceHasBeenUpdated',
                [$title->value],
            )
        );

        $workspaceListItems = $this->getWorkspaceListItems($contentRepository, $currentUser);

        $this->view->assignMultiple([
            'userWorkspaceName' => $this->workspaceService->getPersonalWorkspaceForUser($contentRepositoryId, $currentUser->getId())->workspaceName->value,
            'workspaceListItems' => $workspaceListItems,
        ]);
    }

    /**
     * Delete a workspace
     *
     * TODO: Add force delete option to ignore unpublished nodes or dependent workspaces, the later should be rebased instead
     *
     * @param WorkspaceName $workspaceName A workspace to delete
     * @throws StopActionException
     */
    public function deleteAction(WorkspaceName $workspaceName): void
    {
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);

        $workspace = $contentRepository->findWorkspaceByName($workspaceName);
        if ($workspace === null) {
            $this->addFlashMessage(
                $this->getModuleLabel('workspaces.workspaceDoesNotExist'),
                '',
                Message::SEVERITY_ERROR
            );
            $this->throwStatus(404, 'Workspace does not exist');
        }

        $workspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $workspace->workspaceName);

        if ($workspaceMetadata->classification === WorkspaceClassification::PERSONAL) {
            $this->throwStatus(403, 'Personal workspaces cannot be deleted');
        }

        $dependentWorkspaces = $contentRepository->findWorkspaces()->getDependantWorkspaces($workspaceName);
        if (!$dependentWorkspaces->isEmpty()) {
            $dependentWorkspaceTitles = [];
            /** @var Workspace $dependentWorkspace */
            foreach ($dependentWorkspaces as $dependentWorkspace) {
                $dependentWorkspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $dependentWorkspace->workspaceName);
                $dependentWorkspaceTitles[] = $dependentWorkspaceMetadata->title->value;
            }

            $message = $this->getModuleLabel(
                'workspaces.workspaceCannotBeDeletedBecauseOfDependencies',
                [$workspaceMetadata->title->value, implode(', ', $dependentWorkspaceTitles)],
            );
            $this->addFlashMessage($message, '', Message::SEVERITY_WARNING);
            $this->throwStatus(403, 'Workspace has dependencies');
        }

        $nodesCount = 0;

        try {
            $nodesCount = $contentRepository->projectionState(ChangeFinder::class)
                ->countByContentStreamId(
                    $workspace->currentContentStreamId
                );
        } catch (\Exception $exception) {
            $message = $this->getModuleLabel(
                'workspaces.notDeletedErrorWhileFetchingUnpublishedNodes',
                [$workspaceMetadata->title->value],
            );
            $this->addFlashMessage($message, '', Message::SEVERITY_WARNING);
            $this->throwStatus(500, 'Error while fetching unpublished nodes');
        }
        if ($nodesCount > 0) {
            $message = $this->getModuleLabel(
                'workspaces.workspaceCannotBeDeletedBecauseOfUnpublishedNodes',
                [$workspaceMetadata->title->value, $nodesCount],
                $nodesCount,
            );
            $this->addFlashMessage($message, '', Message::SEVERITY_WARNING);
            $this->throwStatus(403, 'Workspace has unpublished nodes');
        // delete workspace on POST -> todo make this more FLOW-ig by possibly having a DeleteController with post() and get() _or_ by having deleteAction_post and deleteAction_get?? Or a separate action?
        } elseif ($this->request->getHttpRequest()->getMethod() === 'POST') {
            $this->workspaceService->deleteWorkspace($contentRepositoryId, $workspaceName);

            $this->addFlashMessage(
                $this->getModuleLabel(
                    'workspaces.workspaceHasBeenRemoved',
                    [$workspaceMetadata->title->value],
                )
            );
        // Render a confirmation form if the request is not a POST request
        } else {
            $this->view->assign('workspaceName', $workspace->workspaceName->value);
            $this->view->assign('workspaceTitle', $workspaceMetadata->title->value);
        }
    }

    /**
     * Rebase the current users personal workspace onto the given $targetWorkspace and then
     * redirects to the $targetNode in the content module.
     */
    public function rebaseAndRedirectAction(string $targetNode, WorkspaceName $targetWorkspaceName): void
    {
        $targetNodeAddress = NodeAddress::fromJsonString(
            $targetNode
        );
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);

        $targetWorkspace = $contentRepository->findWorkspaceByName($targetWorkspaceName);

        $user = $this->userService->getCurrentUser();
        if ($user === null) {
            throw new \RuntimeException('No account is authenticated', 1710068880);
        }
        $personalWorkspace = $this->workspaceService->getPersonalWorkspaceForUser($targetNodeAddress->contentRepositoryId, $user->getId());

        /** @todo do something else
         * if ($personalWorkspace !== $targetWorkspace) {
         * if ($this->publishingService->getUnpublishedNodesCount($personalWorkspace) > 0) {
         * $message = $this->getModuleLabel(
         * 'workspaces.cantEditBecauseWorkspaceContainsChanges',
         * );
         * $this->addFlashMessage($message, '', Message::SEVERITY_WARNING, [], 1437833387);
         * $this->redirect('show', null, null, ['workspace' => $targetWorkspace]);
         * }
         * $personalWorkspace->setBaseWorkspace($targetWorkspace);
         * $this->workspaceFinder->update($personalWorkspace);
         * }
         */

        $targetNodeAddressInPersonalWorkspace = NodeAddress::create(
            $targetNodeAddress->contentRepositoryId,
            $personalWorkspace->workspaceName,
            $targetNodeAddress->dimensionSpacePoint,
            $targetNodeAddress->aggregateId
        );

        if ($this->packageManager->isPackageAvailable('Neos.Neos.Ui')) {
            $mainRequest = $this->controllerContext->getRequest()->getMainRequest();
            $this->uriBuilder->setRequest($mainRequest);

            $this->redirect(
                'index',
                'Backend',
                'Neos.Neos.Ui',
                ['node' => $targetNodeAddressInPersonalWorkspace->toJson()]
            );
        }

        $this->redirectToUri(
            $this->nodeUriBuilderFactory->forActionRequest($this->request)
                ->uriFor($targetNodeAddressInPersonalWorkspace)
        );
    }

    /**
     * Publish a single document node
     */
    public function publishDocumentAction(string $nodeAddress, WorkspaceName $selectedWorkspace): void
    {
        $nodeAddress = NodeAddress::fromJsonString($nodeAddress);
        $contentRepositoryId = $nodeAddress->contentRepositoryId;
        $this->workspacePublishingService->publishChangesInDocument(
            $contentRepositoryId,
            $selectedWorkspace,
            $nodeAddress->aggregateId
        );

        $this->addFlashMessage($this->getModuleLabel('workspaces.selectedChangeHasBeenPublished'));
        $this->forward('review', null, null, ['workspace' => $selectedWorkspace->value]);
    }

    /**
     * Discard a single document node
     *
     * @throws WorkspaceRebaseFailed
     */
    public function discardDocumentAction(string $nodeAddress, WorkspaceName $selectedWorkspace): void
    {
        $nodeAddress = NodeAddress::fromJsonString($nodeAddress);
        $contentRepositoryId = $nodeAddress->contentRepositoryId;
        $this->workspacePublishingService->discardChangesInDocument(
            $contentRepositoryId,
            $selectedWorkspace,
            $nodeAddress->aggregateId
        );

        $this->addFlashMessage($this->getModuleLabel('workspaces.selectedChangeHasBeenDiscarded'));
        $this->forward('review', null, null, ['workspace' => $selectedWorkspace->value]);
    }

    /**
     * @psalm-param list<string> $nodes
     */
    public function publishOrDiscardNodesAction(array $nodes, string $action, WorkspaceName $workspace): void
    {
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())
            ->contentRepositoryId;

        switch ($action) {
            case 'publish':
                foreach ($nodes as $node) {
                    $nodeAddress = NodeAddress::fromJsonString($node);
                    $this->workspacePublishingService->publishChangesInDocument(
                        $contentRepositoryId,
                        $workspace,
                        $nodeAddress->aggregateId
                    );
                }
                $this->addFlashMessage(
                    $this->getModuleLabel('workspaces.selectedChangesHaveBeenPublished')
                );
                break;
            case 'discard':
                foreach ($nodes as $node) {
                    $nodeAddress = NodeAddress::fromJsonString($node);
                    $this->workspacePublishingService->discardChangesInDocument(
                        $contentRepositoryId,
                        $workspace,
                        $nodeAddress->aggregateId
                    );
                }
                $this->addFlashMessage($this->getModuleLabel('workspaces.selectedChangesHaveBeenDiscarded'));
                break;
            default:
                throw new \RuntimeException('Invalid action "' . htmlspecialchars($action) . '" given.', 1346167441);
        }
        $this->forward('review', null, null, ['workspace' => $workspace->value]);
    }

    /**
     * Publishes the whole workspace
     */
    public function publishWorkspaceAction(WorkspaceName $workspace): void
    {
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $publishingResult = $this->workspacePublishingService->publishWorkspace(
            $contentRepositoryId,
            $workspace,
        );
        $this->addFlashMessage(
            $this->getModuleLabel(
                'workspaces.allChangesInWorkspaceHaveBeenPublished',
                [
                    htmlspecialchars($workspace->value),
                    htmlspecialchars($publishingResult->targetWorkspaceName->value)
                ],
            )
        );
        $this->forward('index');
    }

    public function confirmPublishAllChangesAction(WorkspaceName $workspaceName): void
    {
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $workspace = $contentRepository->findWorkspaceByName($workspaceName);
        if ($workspace === null) {
            $this->addFlashMessage(
                $this->getModuleLabel('workspaces.workspaceDoesNotExist'),
                '',
                Message::SEVERITY_ERROR
            );
            $this->throwStatus(404, 'Workspace does not exist');
        }

        $workspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $workspace->workspaceName);
        $this->view->assignMultiple([
            'workspaceName' => $workspaceName->value,
            'workspaceTitle' => $workspaceMetadata->title->value,
        ]);
    }
    public function confirmDiscardAllChangesAction(WorkspaceName $workspaceName): void
    {
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $workspace = $contentRepository->findWorkspaceByName($workspaceName);
        if ($workspace === null) {
            $this->addFlashMessage(
                $this->getModuleLabel('workspaces.workspaceDoesNotExist'),
                '',
                Message::SEVERITY_ERROR
            );
            $this->throwStatus(404, 'Workspace does not exist');
        }

        $workspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $workspace->workspaceName);
        $this->view->assignMultiple([
            'workspaceName' => $workspaceName->value,
            'workspaceTitle' => $workspaceMetadata->title->value,
        ]);
    }

    public function confirmPublishSelectedChangesAction(WorkspaceName $workspaceName): void
    {
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $workspace = $contentRepository->findWorkspaceByName($workspaceName);
        if ($workspace === null) {
            $this->addFlashMessage(
                $this->getModuleLabel('workspaces.workspaceDoesNotExist'),
                '',
                Message::SEVERITY_ERROR
            );
            $this->throwStatus(404, 'Workspace does not exist');
        }
        $baseWorkspace = $this->requireBaseWorkspace($workspace, $contentRepository);

        $baseWorkspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $baseWorkspace->workspaceName);
        $this->view->assignMultiple([
            'workspaceName' => $workspaceName->value,
            'baseWorkspaceTitle' => $baseWorkspaceMetadata->title->value,
        ]);
    }
    public function confirmDiscardSelectedChangesAction(WorkspaceName $workspaceName): void
    {
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $workspace = $contentRepository->findWorkspaceByName($workspaceName);
        if ($workspace === null) {
            $this->addFlashMessage(
                $this->getModuleLabel('workspaces.workspaceDoesNotExist'),
                '',
                Message::SEVERITY_ERROR
            );
            $this->throwStatus(404, 'Workspace does not exist');
        }

        $workspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $workspace->workspaceName);
        $this->view->assignMultiple([
            'workspaceName' => $workspaceName->value,
            'workspaceTitle' => $workspaceMetadata->title->value,
        ]);
    }

    /**
     * Discards content of the whole workspace
     *
     * @param WorkspaceName $workspace
     */
    public function discardWorkspaceAction(WorkspaceName $workspace): void
    {
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;

        $this->workspacePublishingService->discardAllWorkspaceChanges(
            $contentRepositoryId,
            $workspace,
        );
        $this->addFlashMessage(
            $this->getModuleLabel(
                'workspaces.allChangesInWorkspaceHaveBeenDiscarded',
                [htmlspecialchars($workspace->value)],
            )
        );
        $this->forward('review', null, null, ['workspace' => $workspace->value]);
    }

    /**
     * Computes the number of added, changed and removed nodes for the given workspace
     */
    protected function computePendingChanges(Workspace $selectedWorkspace, ContentRepository $contentRepository): PendingChanges
    {
        $changesCount = ['new' => 0, 'changed' => 0, 'removed' => 0];
        foreach($this->getChangesFromWorkspace($selectedWorkspace, $contentRepository) as $change) {
            if($change->deleted) $changesCount['removed']++;
            elseif($change->created) $changesCount['new']++;
            else $changesCount['changed']++;
        }
        return new PendingChanges(new: $changesCount['new'], changed: $changesCount['changed'], removed:$changesCount['removed']);
    }

    /**
     * Builds an array of changes for sites in the given workspace
     * @return array<string,mixed>
     */
    protected function computeSiteChanges(Workspace $selectedWorkspace, ContentRepository $contentRepository): array
    {
        $siteChanges = [];
        $changes = $this->getChangesFromWorkspace($selectedWorkspace, $contentRepository);
        foreach ($changes as $change) {
            if ($change->originDimensionSpacePoint === null) {
                // todo implement support for change node type!!! Because originDimensionSpacePoint is null currently for that case.
                continue;
            }
            $workspaceName = $selectedWorkspace->workspaceName;
            if ($change->deleted) {
                // If we deleted a node, there is no way for us to anymore find the deleted node in the ContentStream
                // where the node was deleted.
                // Thus, to figure out the rootline for display, we check the *base workspace* Content Stream.
                //
                // This is safe because the UI basically shows what would be removed once the deletion is published.
                $baseWorkspace = $this->requireBaseWorkspace($selectedWorkspace, $contentRepository);
                $workspaceName = $baseWorkspace->workspaceName;
            }
            $subgraph = $contentRepository->getContentGraph($workspaceName)->getSubgraph(
                $change->originDimensionSpacePoint->toDimensionSpacePoint(),
                VisibilityConstraints::withoutRestrictions()
            );

            $node = $subgraph->findNodeById($change->nodeAggregateId);
            if ($node) {
                $documentNode = null;
                $siteNode = null;
                $ancestors = $subgraph->findAncestorNodes(
                    $node->aggregateId,
                    FindAncestorNodesFilter::create()
                );
                $ancestors = Nodes::fromArray([$node])->merge($ancestors);

                $nodePathSegments = [];
                $documentPathSegments = [];
                $documentPathSegmentsNames = [];
                foreach ($ancestors as $ancestor) {
                    $pathSegment = $ancestor->name ?: NodeName::fromString($ancestor->aggregateId->value);
                    // Don't include `sites` path as they are not needed
                    // by the HTML/JS magic and won't be included as `$documentPathSegments`
                    if (!$this->getNodeType($ancestor)->isOfType(NodeTypeNameFactory::NAME_SITES)) {
                        $nodePathSegments[] = $pathSegment;
                    }
                    if ($this->getNodeType($ancestor)->isOfType(NodeTypeNameFactory::NAME_DOCUMENT)) {
                        $documentPathSegments[] = $pathSegment;
                        $documentPathSegmentsNames[] = $this->nodeLabelGenerator->getLabel($ancestor);
                        if (is_null($documentNode)) {
                            $documentNode = $ancestor;
                        }
                    }
                    if ($this->getNodeType($ancestor)->isOfType(NodeTypeNameFactory::NAME_SITE)) {
                        $siteNode = $ancestor;
                    }
                }

                // Neither $documentNode, $siteNode or its cannot really be null, this is just for type checks;
                // We should probably throw an exception though

                if ($documentNode !== null && $siteNode !== null && $siteNode->name) {
                    $siteNodeName = $siteNode->name->value;
                    // Reverse `$documentPathSegments` to start with the site node.
                    // The paths are used for grouping the nodes and for selecting a tree of nodes.
                    $documentPath = implode(
                        '/',
                        array_reverse(
                            array_map(
                                fn(NodeName $nodeName): string => $nodeName->value,
                                $documentPathSegments
                            )
                        )
                    );
                    // Reverse `$nodePathSegments` to start with the site node.
                    // The paths are used for grouping the nodes and for selecting a tree of nodes.
                    $relativePath = implode(
                        '/',
                        array_reverse(
                            array_map(
                                fn(NodeName $nodeName): string => $nodeName->value,
                                $nodePathSegments
                            )
                        )
                    );

                    //ToDo: Consider dimensions
                    if(!isset($siteChanges[$siteNodeName]['documents'][$documentPath]['document'])) {
                        $documentNodeAddress = NodeAddress::create(
                            $contentRepository->id,
                            $selectedWorkspace->workspaceName,
                            $documentNode->originDimensionSpacePoint->toDimensionSpacePoint(),
                            $documentNode->aggregateId
                        );
                        $documentType = $contentRepository->getNodeTypeManager()->getNodeType($documentNode->nodeTypeName);
                        $siteChanges[$siteNodeName]['documents'][$documentPath]['document'] = new DocumentItem(
                            documentBreadCrumb: array_reverse($documentPathSegmentsNames),
                            aggregateId: $documentNodeAddress->aggregateId->value,
                            documentNodeAddress: $documentNodeAddress->toJson(),
                            documentIcon: $documentType?->getFullConfiguration()['ui']['icon'] ?? null
                        );
                    }

                    if ($documentNode->equals($node)) {
                        $siteChanges[$siteNodeName]['documents'][$documentPath]['documentChanges'] = new DocumentChangeItem(
                            isRemoved: $change->deleted,
                            isNew: $change->created,
                            isMoved: $change->moved,
                            isHidden: $documentNode->tags->contain(SubtreeTag::disabled()),
                        );
                    }

                    // As for changes of type `delete` we are using nodes from the live workspace
                    // we can't create a serialized nodeAddress from the node.
                    // Instead, we use the original stored values.
                    $nodeAddress = NodeAddress::create(
                        $contentRepository->id,
                        $selectedWorkspace->workspaceName,
                        $change->originDimensionSpacePoint->toDimensionSpacePoint(),
                        $change->nodeAggregateId
                    );
                    $nodeType = $contentRepository->getNodeTypeManager()->getNodeType($node->nodeTypeName);
                    $dimensions = [];
                    foreach ($node->dimensionSpacePoint->coordinates as $id => $coordinate) {
                        $contentDimension = new ContentDimensionId($id);
                        $dimensions[] = $contentRepository->getContentDimensionSource()
                            ->getDimension($contentDimension)
                            ?->getValue($coordinate)
                            ?->configuration['label'] ?? $coordinate;
                    }
                    $siteChanges[$siteNodeName]['documents'][$documentPath]['changes'][$node->dimensionSpacePoint->hash][$relativePath] = new ChangeItem(
                        serializedNodeAddress: $nodeAddress->toJson(),
                        hidden: $node->tags->contain(SubtreeTag::disabled()),
                        isRemoved: $change->deleted,
                        isNew: $change->created,
                        isMoved: $change->moved,
                        dimensions: $dimensions,
                        lastModificationDateTime: $node->timestamps->lastModified?->format('Y-m-d H:i'),
                        createdDateTime: $node->timestamps->created->format('Y-m-d H:i'),
                        label: $this->nodeLabelGenerator->getLabel($node),
                        icon: $nodeType?->getFullConfiguration()['ui']['icon'],
                        contentChanges: $this->renderContentChanges(
                            $node,
                            $change->contentStreamId,
                            $contentRepository
                        )
                    );
                }
            }

        }

        ksort($siteChanges);
        foreach ($siteChanges as $siteKey => $site) {
            foreach ($site['documents'] as $documentKey => $document) {
                ksort($siteChanges[$siteKey]['documents'][$documentKey]['changes']);
            }
            ksort($siteChanges[$siteKey]['documents']);
        }
        return $siteChanges;
    }

    /**
     * Retrieves the given node's corresponding node in the base content stream
     * (that is, which would be overwritten if the given node would be published)
     */
    protected function getOriginalNode(
        Node $modifiedNode,
        WorkspaceName $baseWorkspaceName,
        ContentRepository $contentRepository,
    ): ?Node {
        $baseSubgraph = $contentRepository->getContentGraph($baseWorkspaceName)->getSubgraph(
            $modifiedNode->dimensionSpacePoint,
            VisibilityConstraints::withoutRestrictions()
        );
        return $baseSubgraph->findNodeById($modifiedNode->aggregateId);
    }

    /**
     * Renders the difference between the original and the changed content of the given node and returns it, along
     * with meta information
     */
    protected function renderContentChanges(
        Node $changedNode,
        ContentStreamId $contentStreamIdOfOriginalNode,
        ContentRepository $contentRepository,
    ): ContentChangeItems {
        $currentWorkspace = $contentRepository->findWorkspaces()->find(
            fn (Workspace $potentialWorkspace) => $potentialWorkspace->currentContentStreamId->equals($contentStreamIdOfOriginalNode)
        );
        $originalNode = null;
        if ($currentWorkspace !== null) {
            $baseWorkspace = $this->requireBaseWorkspace($currentWorkspace, $contentRepository);
            $originalNode = $this->getOriginalNode($changedNode, $baseWorkspace->workspaceName, $contentRepository);
        }

        $contentChanges = [];

        $changeNodePropertiesDefaults = $this->getNodeType($changedNode)->getDefaultValuesForProperties();

        $renderer = new HtmlArrayRenderer();

        $actualOriginalTags = $originalNode?->tags->withoutInherited()->all();
        $actualChangedTags = $changedNode->tags->withoutInherited()->all();

        if ($actualOriginalTags?->equals($actualChangedTags)) {
            $contentChanges['tags'] = new ContentChangeItem(
                properties: new ContentChangeProperties(
                    type: 'tags',
                    propertyLabel: $this->getModuleLabel('workspaces.changedTags'),
                ),
                changes: new TagContentChange(
                    addedTags: $actualChangedTags->difference($actualOriginalTags)->toStringArray(),
                    removedTags: $actualOriginalTags->difference($actualChangedTags)->toStringArray(),
                )
            );
        }
        foreach ($changedNode->properties as $propertyName => $changedPropertyValue) {
            if (
                ($originalNode === null && empty($changedPropertyValue))
                || (
                    isset($changeNodePropertiesDefaults[$propertyName])
                    && $changedPropertyValue === $changeNodePropertiesDefaults[$propertyName]
                )
            ) {
                continue;
            }

            $originalPropertyValue = ($originalNode?->getProperty($propertyName));

            if ($changedPropertyValue === $originalPropertyValue) {
                // TODO  && !$changedNode->isRemoved()
                continue;
            }

            if (!is_object($originalPropertyValue) && !is_object($changedPropertyValue)) {
                $originalSlimmedDownContent = $this->renderSlimmedDownContent($originalPropertyValue);
                // TODO $changedSlimmedDownContent = $changedNode->isRemoved()
                // ? ''
                // : $this->renderSlimmedDownContent($changedPropertyValue);
                $changedSlimmedDownContent = $this->renderSlimmedDownContent($changedPropertyValue);

                $diff = new Diff(
                    explode("\n", $originalSlimmedDownContent),
                    explode("\n", $changedSlimmedDownContent),
                    ['context' => 1]
                );
                $diffArray = $diff->render($renderer);
                $this->postProcessDiffArray($diffArray);

                if (count($diffArray) > 0) {

                    $contentChanges[$propertyName] = new ContentChangeItem(
                        properties: new ContentChangeProperties(
                            type: 'text',
                            propertyLabel: $this->getPropertyLabel($propertyName, $changedNode)
                        ),
                        changes: new TextContentChange(
                            diff: $diffArray
                        )
                    );
                }
                // The && in belows condition is on purpose as creating a thumbnail for comparison only works
                // if actually BOTH are ImageInterface (or NULL).
            } elseif (
                ($originalPropertyValue instanceof ImageInterface || $originalPropertyValue === null)
                && ($changedPropertyValue instanceof ImageInterface || $changedPropertyValue === null)
            ) {
                $contentChanges[$propertyName] = new ContentChangeItem(
                    properties: new ContentChangeProperties(
                        type: 'text',
                        propertyLabel: $this->getPropertyLabel($propertyName, $changedNode)
                    ),
                    changes: new ImageContentChange(
                        original: $originalPropertyValue,
                        changed: $changedPropertyValue
                    )
                );
            } elseif (
                $originalPropertyValue instanceof AssetInterface
                || $changedPropertyValue instanceof AssetInterface
            ) {
                $contentChanges[$propertyName] = new ContentChangeItem(
                    properties: new ContentChangeProperties(
                        type: 'text',
                        propertyLabel: $this->getPropertyLabel($propertyName, $changedNode)
                    ),
                    changes: new AssetContentChange(
                        original: $originalPropertyValue,
                        changed: $changedPropertyValue
                    )
                );
            } elseif ($originalPropertyValue instanceof \DateTime || $changedPropertyValue instanceof \DateTime) {
                $changed = false;
                if (!$changedPropertyValue instanceof \DateTime || !$originalPropertyValue instanceof \DateTime) {
                    $changed = true;
                } elseif ($changedPropertyValue->getTimestamp() !== $originalPropertyValue->getTimestamp()) {
                    $changed = true;
                }
                if ($changed) {
                    $contentChanges[$propertyName] = new ContentChangeItem(
                        properties: new ContentChangeProperties(
                            type: 'text',
                            propertyLabel: $this->getPropertyLabel($propertyName, $changedNode)
                        ),
                        changes: new DateTimeContentChange(
                            original: $originalPropertyValue,
                            changed: $changedPropertyValue
                        )
                    );
                }
            }
        }
        return ContentChangeItems::fromArray($contentChanges);
    }

    /**
     * Renders a slimmed down representation of a property of the given node. The output will be HTML, but does not
     * contain any markup from the original content.
     *
     * Note: It's clear that this method needs to be extracted and moved to a more universal service at some point.
     * However, since we only implemented diff-view support for this particular controller at the moment, it stays
     * here for the time being. Once we start displaying diffs elsewhere, we should refactor the diff rendering part.
     */
    protected function renderSlimmedDownContent(mixed $propertyValue): string
    {
        $content = '';
        if (is_string($propertyValue)) {
            $contentSnippet = preg_replace('/<br[^>]*>/', "\n", $propertyValue) ?: '';
            $contentSnippet = preg_replace('/<[^>]*>/', ' ', $contentSnippet) ?: '';
            $contentSnippet = str_replace('&nbsp;', ' ', $contentSnippet) ?: '';
            $content = trim(preg_replace('/ {2,}/', ' ', $contentSnippet) ?: '');
        }
        return $content;
    }

    /**
     * Tries to determine a label for the specified property
     */
    protected function getPropertyLabel(string $propertyName, Node $changedNode): string
    {
        $properties = $this->getNodeType($changedNode)->getProperties();
        if (
            !isset($properties[$propertyName])
            || !isset($properties[$propertyName]['ui']['label'])
        ) {
            return $propertyName;
        }
        $packageKey = 'Neos.Neos';
        $source = 'Main';
        $id = $properties[$propertyName]['ui']['label'];
        $idParts = explode(':', $id, 3);
        switch (count($idParts)) {
            case 2:
                $packageKey = $idParts[0];
                $id = $idParts[1];
                break;
            case 3:
                $packageKey = $idParts[0];
                $source = str_replace('.', '/', $idParts[1]);
                $id = $idParts[2];
                break;
        }
        return $this->translator->translateById(
            $id,
            [],
            null,
            null,
            $source,
            $packageKey
        ) ?: $properties[$propertyName]['ui']['label'];
    }

    /**
     * A workaround for some missing functionality in the Diff Renderer:
     *
     * This method will check if content in the given diff array is either completely new or has been completely
     * removed and wraps the respective part in <ins> or <del> tags, because the Diff Renderer currently does not
     * do that in these cases.
     *
     * @param array<int|string,mixed> &$diffArray
     */
    protected function postProcessDiffArray(array &$diffArray): void
    {
        foreach ($diffArray as $index => $blocks) {
            foreach ($blocks as $blockIndex => $block) {
                $baseLines = trim(implode('', $block['base']['lines']), " \t\n\r\0\xC2\xA0");
                $changedLines = trim(implode('', $block['changed']['lines']), " \t\n\r\0\xC2\xA0");
                if ($baseLines === '') {
                    foreach ($block['changed']['lines'] as $lineIndex => $line) {
                        $diffArray[$index][$blockIndex]['changed']['lines'][$lineIndex] = '<ins>' . $line . '</ins>';
                    }
                }
                if ($changedLines === '') {
                    foreach ($block['base']['lines'] as $lineIndex => $line) {
                        $diffArray[$index][$blockIndex]['base']['lines'][$lineIndex] = '<del>' . $line . '</del>';
                    }
                }
            }
        }
    }

    /**
     * Creates an array of workspace names and their respective titles which are possible base workspaces for other
     * workspaces.
     * If $excludedWorkspace is set, this workspace and all its base workspaces will be excluded from the list of returned workspaces
     *
     * @return array<string,string>
     */
    protected function prepareBaseWorkspaceOptions(
        ContentRepository $contentRepository,
        WorkspaceName $excludedWorkspace = null,
    ): array {
        $user = $this->userService->getCurrentUser();
        $baseWorkspaceOptions = [];
        $workspaces = $contentRepository->findWorkspaces();
        foreach ($workspaces as $workspace) {
            if (
                $excludedWorkspace !== null) {
                if ($workspace->workspaceName->equals($excludedWorkspace)) {
                    continue;
                }
                if ( $workspaces->getBaseWorkspaces($workspace->workspaceName)->get(
                        $excludedWorkspace
                    ) !== null) {
                    continue;
                }
            }
            $workspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepository->id, $workspace->workspaceName);
            if (!in_array($workspaceMetadata->classification, [WorkspaceClassification::SHARED, WorkspaceClassification::ROOT], true)) {
                continue;
            }
            if ($user === null) {
                continue;
            }
            $permissions = $this->authorizationService->getWorkspacePermissions($contentRepository->id, $workspace->workspaceName, $this->securityContext->getRoles(), $user->getId());
            if (!$permissions->read) {
                continue;
            }
            $baseWorkspaceOptions[$workspace->workspaceName->value] = $workspaceMetadata->title->value;
        }

        // Sort the base workspaces by title, but make sure the live workspace is always on top
        uksort($baseWorkspaceOptions, static function (string $a, string $b) {
            if ($a === 'live') {
                return -1;
            }
            if ($b === 'live') {
                return 1;
            }
            return strcasecmp($a, $b);
        });

        return $baseWorkspaceOptions;
    }

    private function requireBaseWorkspace(
        Workspace $workspace,
        ContentRepository $contentRepository,
    ): Workspace {
        if ($workspace->isRootWorkspace()) {
            throw new \RuntimeException(sprintf('Workspace %s does not have a base-workspace.', $workspace->workspaceName->value), 1734019485);
        }
        $baseWorkspace = $contentRepository->findWorkspaceByName($workspace->baseWorkspaceName);
        if ($baseWorkspace === null) {
            throw new \RuntimeException(sprintf('Base-workspace %s of %s does not exist.', $workspace->baseWorkspaceName->value, $workspace->workspaceName->value), 1734019720);
        }
        return $baseWorkspace;
    }

    /**
     * @param array<int|string,mixed> $arguments
     */
    public function getModuleLabel(string $id, array $arguments = [], mixed $quantity = null): string
    {
        return $this->translator->translateById(
            $id,
            $arguments,
            $quantity,
            null,
            'Main',
            'Neos.Workspace.Ui'
        ) ?: $id;
    }

    protected function getWorkspaceListItems(
        ContentRepository $contentRepository,
        User $userWorkspaceOwner,
    ): WorkspaceListItems {
        $workspaceListItems = [];
        $allWorkspaces = $contentRepository->findWorkspaces();
        // todo this throws "No workspace is assigned to the user with id" for the case user logs first into workspace module before workspace exists!!!
        $userWorkspace = $this->workspaceService->getPersonalWorkspaceForUser($contentRepository->id, $userWorkspaceOwner->getId());
        $userWorkspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepository->id, $userWorkspace->workspaceName);
        $userWorkspacesPermissions = $this->authorizationService->getWorkspacePermissions(
            $contentRepository->id,
            $userWorkspace->workspaceName,
            $this->securityContext->getRoles(),
            $userWorkspaceOwner->getId()
        );

        // add user workspace first
        $workspaceListItems[$userWorkspace->workspaceName->value] = new WorkspaceListItem(
            $userWorkspace->workspaceName->value,
            $userWorkspaceMetadata->classification->value,
            $userWorkspace->status->value,
            $userWorkspaceMetadata->title->value,
            $userWorkspaceMetadata->description->value,
            $userWorkspace->baseWorkspaceName?->value,
            $this->computePendingChanges($userWorkspace, $contentRepository),
            !$allWorkspaces->getDependantWorkspaces($userWorkspace->workspaceName)->isEmpty(),
            $userWorkspaceOwner->getLabel(),
            $userWorkspacesPermissions,
        );

        // add other, accessible workspaces
        foreach ($allWorkspaces as $workspace) {
            $workspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepository->id, $workspace->workspaceName);
            $workspacesPermissions = $this->authorizationService->getWorkspacePermissions(
                $contentRepository->id,
                $workspace->workspaceName,
                $this->securityContext->getRoles(),
                $this->userService->getCurrentUser()?->getId()
            );

            // ignore root workspaces, because they will not be shown in the UI
            if ($workspace->isRootWorkspace()) {
                continue;
            }

            // TODO use owner/WorkspaceRoleAssignment?
            // TODO: If user is allowed to edit child workspace, we need to at least show the parent workspaces in the list
            if ($workspacesPermissions->read === false) {
                continue;
            }

            $workspaceOwner = $workspaceMetadata->ownerUserId
                ? $this->userService->findUserById($workspaceMetadata->ownerUserId)
                : null;

            $workspaceListItems[$workspace->workspaceName->value] = new WorkspaceListItem(
                $workspace->workspaceName->value,
                $workspaceMetadata->classification->value,
                $workspace->status->value,
                $workspaceMetadata->title->value,
                $workspaceMetadata->description->value,
                $workspace->baseWorkspaceName->value,
                $this->computePendingChanges($workspace, $contentRepository),
                !$allWorkspaces->getDependantWorkspaces($workspace->workspaceName)->isEmpty(),
                $workspaceOwner?->getLabel(),
                $workspacesPermissions,
            );
        }
        return WorkspaceListItems::fromArray($workspaceListItems);
    }

    protected function getChangesFromWorkspace(Workspace $selectedWorkspace,ContentRepository $contentRepository ): Changes{
        return $contentRepository->projectionState(ChangeFinder::class)
            ->findByContentStreamId(
                $selectedWorkspace->currentContentStreamId
            );
    }
}
