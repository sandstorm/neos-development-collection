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

use Doctrine\DBAL\Exception as DBALException;
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
use Neos\Flow\Http\Exception;
use Neos\Flow\I18n\Exception\IndexOutOfBoundsException;
use Neos\Flow\I18n\Exception\InvalidFormatPlaceholderException;
use Neos\Flow\I18n\Translator;
use Neos\Flow\Mvc\Exception\StopActionException;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\Package\PackageManager;
use Neos\Flow\Property\PropertyMapper;
use Neos\Flow\Security\Context;
use Neos\Flow\Security\Policy\PolicyService;
use Neos\Fusion\View\FusionView;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\ImageInterface;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Neos\Domain\Model\User;
use Neos\Neos\Domain\Model\UserId;
use Neos\Neos\Domain\Model\WorkspaceClassification;
use Neos\Neos\Domain\Model\WorkspaceDescription;
use Neos\Neos\Domain\Model\WorkspaceRole;
use Neos\Neos\Domain\Model\WorkspaceRoleAssignment;
use Neos\Neos\Domain\Model\WorkspaceRoleSubject;
use Neos\Neos\Domain\Model\WorkspaceRoleSubjectType;
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
use Neos\Neos\Utility\NodeTypeWithFallbackProvider;
use Neos\Neos\Security\Authorization\ContentRepositoryAuthorizationService;
use Neos\Workspace\Ui\ViewModel\ConfirmDeleteWorkspaceRoleAssignmentFormData;
use Neos\Workspace\Ui\ViewModel\CreateWorkspaceRoleAssignmentFormData;
use Neos\Workspace\Ui\ViewModel\ChangeItem;
use Neos\Workspace\Ui\ViewModel\ContentChangeItem;
use Neos\Workspace\Ui\ViewModel\ContentChangeItems;
use Neos\Workspace\Ui\ViewModel\ContentChangeProperties;
use Neos\Workspace\Ui\ViewModel\ContentChanges\ImageContentChange;
use Neos\Workspace\Ui\ViewModel\ContentChanges\TextContentChange;
use Neos\Workspace\Ui\ViewModel\ContentChanges\AssetContentChange;
use Neos\Workspace\Ui\ViewModel\ContentChanges\DateTimeContentChange;
use Neos\Workspace\Ui\ViewModel\ContentChanges\TagContentChange;
use Neos\Workspace\Ui\ViewModel\DocumentChangeItem;
use Neos\Workspace\Ui\ViewModel\DocumentItem;
use Neos\Workspace\Ui\ViewModel\EditWorkspaceFormData;
use Neos\Workspace\Ui\ViewModel\EditWorkspaceRoleAssignmentsFormData;
use Neos\Workspace\Ui\ViewModel\PendingChanges;
use Neos\Workspace\Ui\ViewModel\RoleAssignmentListItem;
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
    public function indexAction(): void
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
        $userWorkspace = $this->getUserWorkspace($contentRepository);
        $workspaceListItems = $this->getWorkspaceListItems($userWorkspace, $contentRepository);

        $this->view->assignMultiple([
            'userWorkspaceName' => $userWorkspace->workspaceName->value,
            'workspaceListItems' => $workspaceListItems,
            'flashMessages' => $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush(),
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
            $this->redirect('index');
        }

        $workspacePermissions = $this->authorizationService->getWorkspacePermissions($contentRepositoryId, $workspace, $this->securityContext->getRoles(), $currentUser->getId());
        if(!$workspacePermissions->read){
            $this->addFlashMessage(
                $this->getModuleLabel('workspaces.changes.noPermissionToReadWorkspace'),
                '',
                Message::SEVERITY_ERROR
            );
            $this->redirect('index');
        }
        $workspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $workspace);
        $baseWorkspaceMetadata = null;
        $baseWorkspacePermissions = null;
        if ($workspaceObj->baseWorkspaceName !== null) {
            $baseWorkspace = $contentRepository->findWorkspaceByName($workspaceObj->baseWorkspaceName);
            assert($baseWorkspace !== null);
            $baseWorkspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $baseWorkspace->workspaceName);
            $baseWorkspacePermissions = $this->authorizationService->getWorkspacePermissions($contentRepositoryId, $baseWorkspace->workspaceName, $this->securityContext->getRoles(), $currentUser->getId());
        }
        $this->view->assignMultiple([
            'selectedWorkspaceName' => $workspaceObj->workspaceName->value,
            'selectedWorkspaceLabel' => $workspaceMetadata->title->value,
            'baseWorkspaceName' => $workspaceObj->baseWorkspaceName,
            'baseWorkspaceLabel' => $baseWorkspaceMetadata?->title->value,
            'canPublishToBaseWorkspace' => $baseWorkspacePermissions?->write ?? false,
            'canPublishToWorkspace' => $workspacePermissions?->write ?? false,
            'siteChanges' => $this->computeSiteChanges($workspaceObj, $contentRepository),
            'contentDimensions' => $contentRepository->getContentDimensionSource()->getContentDimensionsOrderedByPriority()
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
    ): void {
        $currentUser = $this->userService->getCurrentUser();
        if ($currentUser === null) {
            throw new \RuntimeException('No user authenticated', 1718303756);
        }

        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $workspaceName = $this->workspaceService->getUniqueWorkspaceName($contentRepositoryId, $title->value);

        try {
            $this->workspaceService->createSharedWorkspace(
                $contentRepositoryId,
                $workspaceName,
                $title,
                $description,
                $baseWorkspace,
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
        $this->workspaceService->assignWorkspaceRole(
            $contentRepositoryId,
            $workspaceName,
            WorkspaceRoleAssignment::createForUser(
                $currentUser->getId(),
                WorkspaceRole::MANAGER,
            )
        );
        $this->workspaceService->assignWorkspaceRole(
            $contentRepositoryId,
            $workspaceName,
            WorkspaceRoleAssignment::createForGroup(
                'Neos.Neos:AbstractEditor',
                WorkspaceRole::COLLABORATOR,
            )
        );
        $this->addFlashMessage($this->getModuleLabel('workspaces.workspaceHasBeenCreated', [$title->value]));
        $this->redirect('index');
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
     * @param WorkspaceName $workspaceName
     * @param WorkspaceTitle $title Human friendly title of the workspace, for example "Christmas Campaign"
     * @param WorkspaceDescription $description A description explaining the purpose of the new workspace
     * @return void
     */
    public function updateAction(
        WorkspaceName $workspaceName,
        WorkspaceTitle $title,
        WorkspaceDescription $description,
        WorkspaceName $baseWorkspace,
    ): void {
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
        $this->workspaceService->setBaseWorkspace(
            $contentRepositoryId,
            $workspaceName,
            $baseWorkspace,
        );

        $this->addFlashMessage(
            $this->getModuleLabel(
                'workspaces.workspaceHasBeenUpdated',
                [$title->value],
            )
        );

        $userWorkspace = $this->getUserWorkspace($contentRepository);
        $workspaceListItems = $this->getWorkspaceListItems($userWorkspace, $contentRepository);

        $this->view->assignMultiple([
            'userWorkspaceName' => $userWorkspace->workspaceName->value,
            'workspaceListItems' => $workspaceListItems,
        ]);
    }

    /**
     * Delete a workspace
     *
     * TODO: Add force delete option to ignore unpublished nodes or dependent workspaces, the later should be rebased instead
     *
     * @param WorkspaceName $workspaceName A workspace to delete
     * @throws IndexOutOfBoundsException
     * @throws InvalidFormatPlaceholderException
     * @throws StopActionException
     * @throws DBALException
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
        // delete workspace on POST
        } elseif ($this->request->getHttpRequest()->getMethod() === 'POST') {
            $this->workspaceService->deleteWorkspace($contentRepositoryId, $workspaceName);

            $this->addFlashMessage(
                $this->getModuleLabel(
                    'workspaces.workspaceHasBeenRemoved',
                    [$workspaceMetadata->title->value],
                )
            );

            // WHY: Redirect to refresh data on page (e.g. workspace list & count)
            $this->redirect('index');
        // Render a confirmation form if the request is not a POST request
        } else {
            $this->view->assign('workspaceName', $workspace->workspaceName->value);
            $this->view->assign('workspaceTitle', $workspaceMetadata->title->value);
        }
    }


    public function editWorkspaceRoleAssignmentsAction(WorkspaceName $workspaceName): void
    {
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $workspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $workspaceName);

        // TODO: Render a form to edit role assignments
        // TODO can current user see/edit role assignments?
        $roleAssignmentsVisible = true;
        $roleAssignmentsEditable = true;

        /** @var array<RoleAssignmentListItem> $workspaceRoleAssignments */
        $workspaceRoleAssignments = [];

        foreach ($this->workspaceService->getWorkspaceRoleAssignments($contentRepositoryId, $workspaceName) as $workspaceRoleAssignment) {
            $subjectLabel = match ($workspaceRoleAssignment->subjectType) {
                WorkspaceRoleSubjectType::USER => $this->userService->findUserById(UserId::fromString($workspaceRoleAssignment->subject->value))?->getLabel(),
                default => $workspaceRoleAssignment->subject->value,
            };

            $roleLabel = $workspaceRoleAssignment->role->value;

            $workspaceRoleAssignments[] = new RoleAssignmentListItem(
                subjectValue: $workspaceRoleAssignment->subject->value,
                subjectLabel: $subjectLabel,
                subjectTypeValue: $workspaceRoleAssignment->subjectType->value,
                roleLabel: $roleLabel,
                subjectType: $workspaceRoleAssignment->subjectType->value,
            );
        }



        $editWorkspaceRoleAssignmentsFormData = new EditWorkspaceRoleAssignmentsFormData(
            workspaceName: $workspaceName,
            workspaceTitle: $workspaceMetadata->title,
            roleAssignmentsEditable: $roleAssignmentsEditable,
            roleAssignments: $workspaceRoleAssignments,
        );

        $this->view->assign('editWorkspaceRoleAssignmentsFormData', $editWorkspaceRoleAssignmentsFormData);
    }

    public function createWorkspaceRoleAssignmentAction(WorkspaceName $workspaceName): void
    {
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $workspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $workspaceName);

        $userOptions = [];
        foreach ($this->userService->getUsers()->toArray() as $user) {
            $userOptions[$user->getId()->value] = $user->getLabel();
        }

        $rolesInSystem = $this->policyService->getRoles();
        $groupOptions = [];
        foreach ($rolesInSystem as $role) {
            $groupOptions[$role->getIdentifier()] = $role->getLabel();
        }

        $workspaceRoleSubjectTypes = WorkspaceRoleSubjectType::cases();
        /** @var array<string, string> $subjectTypeOptions where key is the Id and value is the translated label of the SubjectType */
        $subjectTypeOptions = [];
        foreach ($workspaceRoleSubjectTypes as $workspaceRoleSubjectType) {
            $subjectTypeOptions[$workspaceRoleSubjectType->value] = $this->getModuleLabel("workspaces.workspace.workspaceRoleAssignment.subjectType.label.$workspaceRoleSubjectType->value");
        }

        $workspaceRoles = WorkspaceRole::cases();
        /** @var array<string, string> $roleOptions where key is the Id and value is the translated label of the Role */
        $roleOptions = [];
        foreach ($workspaceRoles as $workspaceRole) {
            $roleOptions[$workspaceRole->value] = $this->getModuleLabel("workspaces.workspace.workspaceRoleAssignment.role.label.$workspaceRole->value");
        }

        $this->view->assign('createWorkspaceRoleAssignmentFormData', new CreateWorkspaceRoleAssignmentFormData(
            workspaceName: $workspaceName,
            workspaceTitle: $workspaceMetadata->title,
            userOptions: $userOptions,
            groupOptions: $groupOptions,
            subjectTypeOptions: $subjectTypeOptions,
            roleOptions: $roleOptions,
        ));
    }

    public function addWorkspaceRoleAssignmentAction(
        WorkspaceName $workspaceName,
        string $subjectValue,
        string $subjectTypeValue,
        string $roleValue,
    ): void
    {
        // TODO: Validate if user can add role assignment to workspace

        $subject = WorkspaceRoleSubject::fromString($subjectValue);
        $subjectType = WorkspaceRoleSubjectType::from($subjectTypeValue);
        $role = WorkspaceRole::from($roleValue);

        if ($subjectType === WorkspaceRoleSubjectType::USER) {
            $this->addUserRoleAssignment($workspaceName, $subject, $role);
        } elseif ($subjectType === WorkspaceRoleSubjectType::GROUP) {
            $this->addGroupRoleAssignment($workspaceName, $subject, $role);
        } else {
            $this->addFlashMessage(
                $this->getModuleLabel('workspaces.roleAssignmentCouldNotBeAdded'),
                '',
                Message::SEVERITY_ERROR
            );
            $this->throwStatus(400, 'Invalid subject type');
        }
    }

    public function confirmDeleteWorkspaceRoleAssignmentAction(WorkspaceName $workspaceName, string $subjectValue, string $subjectType): void
    {
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $workspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $workspaceName);

        $confirmDeleteWorkspaceRoleAssignmentFormData = new ConfirmDeleteWorkspaceRoleAssignmentFormData(
            workspaceName: $workspaceName,
            workspaceTitle: $workspaceMetadata->title,
            subjectValue: $subjectValue,
            subjectType: $subjectType,
        );

        $this->view->assign('confirmDeleteWorkspaceRoleAssignmentFormData', $confirmDeleteWorkspaceRoleAssignmentFormData);
    }

    public function deleteWorkspaceRoleAssignmentAction(WorkspaceName $workspaceName, string $subjectValue, string $subjectType): void
    {
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        try {
            $this->workspaceService->unassignWorkspaceRole(
                $contentRepositoryId,
                $workspaceName,
                WorkspaceRoleSubjectType::from($subjectType),
                WorkspaceRoleSubject::fromString($subjectValue),
            );
        } catch (\Exception $e) {
            // TODO: error handling
            $this->addFlashMessage(
                $this->getModuleLabel('workspaces.roleAssignmentCouldNotBeDeleted'),
                '',
                Message::SEVERITY_ERROR
            );
            $this->throwStatus(500, 'Role assignment could not be deleted');
        }

        $this->redirect('editWorkspaceRoleAssignments', null, null, ['workspaceName' => $workspaceName->value]);
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
     *
     * @param string $nodeAddress
     * @param WorkspaceName $selectedWorkspace
     * @throws Exception
     * @throws MissingActionNameException
     * @throws StopActionException
     * @throws WorkspaceRebaseFailed
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
        $this->redirect('review', null, null, ['workspace' => $selectedWorkspace->value]);
    }

    /**
     * Discard a single document node
     *
     * @param string $nodeAddress
     * @param WorkspaceName $selectedWorkspace
     * @throws StopActionException
     * @throws WorkspaceRebaseFailed
     * @throws Exception
     * @throws MissingActionNameException
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

        $this->redirect('review', null, null, ['workspace' => $selectedWorkspace->value]);

    }

    /**
     * @psalm-param list<string> $nodes
     * @throws IndexOutOfBoundsException
     * @throws InvalidFormatPlaceholderException
     * @throws StopActionException
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
                //todo: make flashmessage work with htmx
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

        $this->redirect('review', null, null, ['workspace' => $workspace->value]);
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
        //todo make redirect work
        $this->redirect('index');
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
        $baseWorkspace = $this->getBaseWorkspaceWhenSureItExists($workspace, $contentRepository);

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
        //todo make redirect to index work
        $this->redirect('review', null, null, ['workspace' => $workspace->value]);
    }

    /**
     * Computes the number of added, changed and removed nodes for the given workspace
     *
     * @param Workspace $selectedWorkspace
     * @param ContentRepository $contentRepository
     * @return PendingChanges
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
            $workspaceName = $selectedWorkspace->workspaceName;
            if ($change->deleted) {
                // If we deleted a node, there is no way for us to anymore find the deleted node in the ContentStream
                // where the node was deleted.
                // Thus, to figure out the rootline for display, we check the *base workspace* Content Stream.
                //
                // This is safe because the UI basically shows what would be removed once the deletion is published.
                $baseWorkspace = $this->getBaseWorkspaceWhenSureItExists($selectedWorkspace, $contentRepository);
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
                            documentIcon: $documentType->getFullConfiguration()['ui']['icon']
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
                        $dimensions[] = $contentRepository->getContentDimensionSource()->getDimension($contentDimension)->getValue($coordinate)->configuration['label'];
                    }
                    $siteChanges[$siteNodeName]['documents'][$documentPath]['changes'][$relativePath] = new ChangeItem (
                        serializedNodeAddress: $nodeAddress->toJson(),
                        hidden: $node->tags->contain(SubtreeTag::disabled()),
                        isRemoved: $change->deleted,
                        isNew: $change->created,
                        isMoved: $change->moved,
                        dimensions: $dimensions,
                        lastModificationDateTime: $node->timestamps->lastModified?->format('Y-m-d H:i'),
                        createdDateTime: $node->timestamps->created?->format('Y-m-d H:i'),
                        label: $this->nodeLabelGenerator->getLabel($node),
                        icon: $nodeType->getFullConfiguration()['ui']['icon'],
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
     * with meta information, in an array.
     *
     * @return array<string,mixed>
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
            $baseWorkspace = $this->getBaseWorkspaceWhenSureItExists($currentWorkspace, $contentRepository);
            $originalNode = $this->getOriginalNode($changedNode, $baseWorkspace->workspaceName, $contentRepository);
        }

        $contentChanges = [];

        $changeNodePropertiesDefaults = $this->getNodeType($changedNode)->getDefaultValuesForProperties();

        $renderer = new HtmlArrayRenderer();
        if($originalNode?->tags->toStringArray() != $changedNode?->tags->toStringArray()) {
            $contentChanges['tags'] = new ContentChangeItem(
                properties: new ContentChangeProperties(
                    type: 'tags',
                    propertyLabel: $this->getModuleLabel('workspaces.changedTags'),
                ),
                changes: new TagContentChange(
                    addedTags: array_diff($changedNode->tags->toStringArray(), $originalNode->tags->toStringArray()),
                    removedTags: array_diff($originalNode->tags->toStringArray(), $changedNode->tags->toStringArray()),
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
     *
     * @param mixed $propertyValue
     * @return string
     */
    protected function renderSlimmedDownContent($propertyValue)
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
     *
     * @param string $propertyName
     * @param Node $changedNode
     * @return string
     */
    protected function getPropertyLabel($propertyName, Node $changedNode)
    {
        $properties = $this->getNodeType($changedNode)->getProperties();
        if (
            !isset($properties[$propertyName])
            || !isset($properties[$propertyName]['ui']['label'])
        ) {
            return $propertyName;
        }
        return $properties[$propertyName]['ui']['label'];
    }

    /**
     * A workaround for some missing functionality in the Diff Renderer:
     *
     * This method will check if content in the given diff array is either completely new or has been completely
     * removed and wraps the respective part in <ins> or <del> tags, because the Diff Renderer currently does not
     * do that in these cases.
     *
     * @param array<int|string,mixed> &$diffArray
     * @return void
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
     * @param ContentRepository $contentRepository
     * @param WorkspaceName|null $excludedWorkspace
     * @return array<string,?string>
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
            if (!$permissions->manage) {
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

    /**
     * Creates an array of user names and their respective labels which are possible owners for a workspace.
     *
     * @return array<int|string,string>
     */
    protected function prepareOwnerOptions(): array
    {
        $ownerOptions = ['' => '-'];
        foreach ($this->userService->getUsers() as $user) {
            /** @var User $user */
            $ownerOptions[$this->persistenceManager->getIdentifierByObject($user)] = $user->getLabel();
        }

        return $ownerOptions;
    }

    private function getBaseWorkspaceWhenSureItExists(
        Workspace $workspace,
        ContentRepository $contentRepository,
    ): Workspace {
        /** @var WorkspaceName $baseWorkspaceName We expect this to exist */
        $baseWorkspaceName = $workspace->baseWorkspaceName;
        /** @var Workspace $baseWorkspace We expect this to exist */
        $baseWorkspace = $contentRepository->findWorkspaceByName($baseWorkspaceName);

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

    protected function getUserWorkspace(ContentRepository $contentRepository): Workspace
    {
        $currentUser = $this->userService->getCurrentUser();
        if ($currentUser === null) {
            throw new \RuntimeException('No user is authenticated', 1729505338);
        }
        return $this->workspaceService->getPersonalWorkspaceForUser($contentRepository->id, $currentUser->getId());
    }

    protected function getWorkspaceListItems(
        Workspace $userWorkspace,
        ContentRepository $contentRepository
    ): WorkspaceListItems {
        $userWorkspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepository->id, $userWorkspace->workspaceName);
        $userWorkspacesPermissions = $this->authorizationService->getWorkspacePermissions(
            $contentRepository->id,
            $userWorkspace->workspaceName,
            $this->securityContext->getRoles(),
            $this->userService->getCurrentUser()->getId()
        );

        $allWorkspaces = $contentRepository->findWorkspaces();

        $userWorkspaceOwner = $this->userService->findUserById($userWorkspaceMetadata->ownerUserId);

        // add user workspace first
        $workspaceListItems = [];
        $workspaceListItems[$userWorkspace->workspaceName->value] = new WorkspaceListItem(
            $userWorkspace->workspaceName->value,
            $userWorkspaceMetadata->classification->value,
            $userWorkspace->status->value,
            $userWorkspaceMetadata->title->value,
            $userWorkspaceMetadata->description->value,
            $userWorkspace->baseWorkspaceName?->value,
            $this->computePendingChanges($userWorkspace, $contentRepository),
            !$allWorkspaces->getDependantWorkspaces($userWorkspace->workspaceName)->isEmpty(),
            $userWorkspaceOwner?->getLabel(),
            $userWorkspacesPermissions,
        );

        // add other, accessible workspaces
        foreach ($allWorkspaces as $workspace) {
            $workspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepository->id, $workspace->workspaceName);
            $workspacesPermissions = $this->authorizationService->getWorkspacePermissions(
                $contentRepository->id,
                $workspace->workspaceName,
                $this->securityContext->getRoles(),
                $this->userService->getCurrentUser()->getId()
            );

            // ignore root workspaces, because they will not be shown in the UI
            if ($workspaceMetadata->classification === WorkspaceClassification::ROOT) {
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
                $workspace->baseWorkspaceName?->value,
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

    private function addUserRoleAssignment(WorkspaceName $workspaceName, WorkspaceRoleSubject $subject, WorkspaceRole $role): void
    {
        if ($this->userService->findUserById(UserId::fromString($subject->value)) === null) {
            $this->addFlashMessage(
                $this->getModuleLabel('workspaces.roleAssignmentCouldNotBeAdded'),
                '',
                Message::SEVERITY_ERROR
            );
            $this->throwStatus(400, 'Invalid user');
        }

        $this->workspaceService->assignWorkspaceRole(
            SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId,
            $workspaceName,
            WorkspaceRoleAssignment::createForUser(UserId::fromString($subject->value), $role)
        );
    }

    private function addGroupRoleAssignment(WorkspaceName $workspaceName, WorkspaceRoleSubject $subject, WorkspaceRole $role)
    {
        // TODO check if group exists?
        $this->workspaceService->assignWorkspaceRole(
            SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId,
            $workspaceName,
            WorkspaceRoleAssignment::createForGroup($subject->value, $role)
        );
    }
}
