----------------------------------------
The workspace module feature description
----------------------------------------

This document describes the user stories for the workspace management module in Neos CMS.
If new features are added to the module, they should be described as user stories in this document.
If features are removed or changed, the user stories should be updated accordingly.
The user stories should be written in a way that is understandable to non-technical users.

List workspaces
---------------

As an editor, I want to see a list of workspaces, so that I can see which workspaces are available and what their status is.

The list should show the following information for each workspace:

* The workspace title / name
* A description of the workspace to explain its purpose
* The last modification date of its content (TODO)
* The creator of the workspace (TODO)
* The status of the workspace (e.g. "Published", "Stale", "Outdated")
* The number of pending changes in the workspace and their type (e.g. "New content", "Modified content", "Deleted content")
* A list of actions that can be performed on the workspace (e.g. "Review", "Edit", "Delete")

As an administrator or workspace manager I want to be able to see which workspaces are actively used, so that I can clean up unused or stale workspaces.
The list should therefore be sortable by the last modification date of the workspace and stale workspaces visually highlighted.

Additional requirements:
########################

* The list should work well with 100 workspaces
* The list should be able to show nested workspaces with up to 4 levels of nesting


Create a new workspace
----------------------

As an editor, I want to be able to create a new workspace, so that I can work on changes without affecting the live site.

When creating a new workspace, I should be able to specify the following information:

* The title of the workspace
* A description of the workspace to explain its purpose
* The parent workspace that the new workspace should be based on
* The initial visibility of the workspace (e.g. "Shared", "Private")

Advanced configuration can be done after the workspace has been created.

Edit a workspace
----------------

As an editor, I want to be able to edit the properties of a workspace,
so that I can update its title, description, parent workspace, and visibility.

When editing a workspace, I should be able to change the following information:

* The title of the workspace
* A description of the workspace to explain its purpose
* The parent workspace that the workspace should be based on
* The visibility of the workspace (e.g. "Shared", "Private") and its access control list (ACL)

Changing the base workspace
###########################

When changing the parent workspace, the user should be able to choose from a list of possible workspaces.

* If the edited workspace has sub-workspaces (shared, personal or private) that are based on the edited workspace,
the user should be warned that the changes will affect the sub-workspaces as well. The names of the affected workspaces
should be displayed if possible.
* If the user has no access to the sub-workspaces, the user see the number of affected workspaces.

Review a workspace
------------------

As an editor, I want to be able to review the changes in a workspace, so that I can see what has been changed
and decide whether to publish the changes to the live site.

Delete a workspace
------------------

As an editor, I want to be able to delete a workspace, so that I can clean up unused or stale workspaces.

When deleting a workspace, the user should be warned that the action cannot be undone and that all changes in the workspace will be lost.
The number of pending changes in the workspace should be displayed to help the user decide whether to delete the workspace.
When the workspace has sub-workspaces, the user should be warned that the sub-workspaces will be deleted as well.
The names of the affected workspaces should be displayed if possible.
If the user has no access to the sub-workspaces, the user see the number of affected workspaces.
