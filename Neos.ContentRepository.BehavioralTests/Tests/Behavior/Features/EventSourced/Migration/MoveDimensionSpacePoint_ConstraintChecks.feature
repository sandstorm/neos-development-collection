@contentrepository @adapters=DoctrineDBAL
Feature: Move dimension space point

  These are the constraint check tests to prevent damage to the content repository state

  Background: Set up the stage
    Given using the following content dimensions:
      | Identifier | Values          | Generalizations      |
      | language   | mul, de, en, ch | ch->de->mul, en->mul |
    And using the following node types:
    """yaml
    'Neos.ContentRepository:Root':
      constraints:
        nodeTypes:
          'Neos.ContentRepository.Testing:Document': true
          'Neos.ContentRepository.Testing:OtherDocument': true

    'Neos.ContentRepository.Testing:Document':
      properties:
        text:
          type: string
    'Neos.ContentRepository.Testing:OtherDocument': []
    """
    And using identifier "default", I define a content repository
    And I am in content repository "default"
    And the command CreateRootWorkspace is executed with payload:
      | Key                  | Value                |
      | workspaceName        | "live"               |
      | newContentStreamId   | "cs-identifier"      |
    And I am in workspace "live"
    And the command CreateRootNodeAggregateWithNode is executed with payload:
      | Key             | Value                         |
      | nodeAggregateId | "lady-eleonode-rootford"      |
      | nodeTypeName    | "Neos.ContentRepository:Root" |
    # Node /document
    When the command CreateNodeAggregateWithNode is executed with payload:
      | Key                       | Value                                     |
      | nodeAggregateId           | "sir-david-nodenborough"                  |
      | nodeTypeName              | "Neos.ContentRepository.Testing:Document" |
      | originDimensionSpacePoint | {"language": "de"}                        |
      | parentNodeAggregateId     | "lady-eleonode-rootford"                  |

  Scenario: Error case - there's already an edge in the target dimension
    When I change the content dimensions in content repository "default" to:
      | Identifier | Values  | Generalizations |
      | language   | mul, ch | ch->mul         |

    When I run the following node migration for workspace "live", creating target workspace "migration-workspace" on contentStreamId "migration-cs" and exceptions are caught:
    """yaml
    migration:
      -
        transformations:
          -
            type: 'MoveDimensionSpacePoint'
            settings:
              from: { language: 'de' }
              to: { language: 'ch' }
    """
    Then the last command should have thrown an exception of type "DimensionSpacePointAlreadyExists"

  Scenario: Error case - the target dimension is not configured
    When I run the following node migration for workspace "live", creating target workspace "migration-workspace" on contentStreamId "migration-cs" and exceptions are caught:
    """yaml
    migration:
      -
        transformations:
          -
            type: 'MoveDimensionSpacePoint'
            settings:
              from: { language: 'de' }
              to: { language: 'notexisting' }
    """
    Then the last command should have thrown an exception of type "DimensionSpacePointNotFound"

  Scenario: Error case - there are changes in another workspace
    Given the command CreateWorkspace is executed with payload:
      | Key                | Value                |
      | workspaceName      | "user-test"          |
      | baseWorkspaceName  | "live"               |
      | newContentStreamId | "user-cs-identifier" |
    And the command SetNodeProperties is executed with payload:
      | Key                       | Value                    |
      | workspaceName             | "user-test"              |
      | nodeAggregateId           | "sir-david-nodenborough" |
      | originDimensionSpacePoint | {"language": "de"}       |
      | propertyValues            | {"text": "changed"}      |
    When I change the content dimensions in content repository "default" to:
      | Identifier | Values       | Generalizations   |
      | language   | mul, ch, gsw | ch->mul, gsw->mul |
    And I run the following node migration for workspace "live", creating target workspace "migration-workspace" on contentStreamId "migration-cs" and exceptions are caught:
    """yaml
    migration:
      -
        transformations:
          -
            type: 'MoveDimensionSpacePoint'
            settings:
              from: { language: 'de' }
              to: { language: 'gsw' }
    """
    Then the last command should have thrown an exception of type "WorkspacesContainChanges"
