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
      | Key                | Value           |
      | workspaceName      | "live"          |
      | newContentStreamId | "cs-identifier" |
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

  Scenario: Error case - the move violates the projected fallbacks which have to be resolved (replaced by variants) first.
    Given the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId                  | nodeTypeName                            | parentNodeAggregateId  | nodeName                     | originDimensionSpacePoint |
      | varied-nodenborough              | Neos.ContentRepository.Testing:Document | lady-eleonode-rootford | varied-document              | {"language": "de"}        |
      | only-specialization-nodenborough | Neos.ContentRepository.Testing:Document | lady-eleonode-rootford | only-specialization-document | {"language": "ch"}        |
      | only-source-nodenborough         | Neos.ContentRepository.Testing:Document | lady-eleonode-rootford | only-source-document         | {"language": "de"}        |
      | nody-mc-nodeface                 | Neos.ContentRepository.Testing:Document | varied-nodenborough    | child-document               | {"language": "de"}        |
    And the command CreateNodeVariant is executed with payload:
      | Key             | Value                 |
      | nodeAggregateId | "varied-nodenborough" |
      | sourceOrigin    | {"language":"de"}     |
      | targetOrigin    | {"language":"ch"}     |
    And the command RemoveNodeAggregate is executed with payload:
      | Key                          | Value                      |
      | nodeAggregateId              | "only-source-nodenborough" |
      | coveredDimensionSpacePoint   | {"language":"ch"}          |
      | nodeVariantSelectionStrategy | "allSpecializations"       |

    When I change the content dimensions in content repository "default" to:
      | Identifier | Values           | Generalizations            |
      | language   | mul, en, ch, gsw | ch->mul, gsw->mul, en->mul |
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
    Then the last command should have thrown an exception of type "NodeAggregateDoesCurrentlyNotOccupyDimensionSpacePoint"

    # For the success case see MoveDimensionSpacePoint "Success Case - after resolving all fallbacks via variation, a generalization can be moved"

