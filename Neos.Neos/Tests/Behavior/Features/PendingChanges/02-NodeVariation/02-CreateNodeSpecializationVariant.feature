@contentrepository @adapters=DoctrineDBAL
@flowEntities
Feature: Create node specialization variant

  Background:
    Given using the following content dimensions:
      | Identifier | Values    | Generalizations |
      | language   | de,gsw,fr | gsw->de, fr     |
    And using the following node types:
    """yaml
    'Neos.ContentRepository.Testing:Node':
      properties:
        text:
          type: string

    """
    And using identifier "default", I define a content repository
    And I am in content repository "default"
    And the command CreateRootWorkspace is executed with payload:
      | Key                  | Value                |
      | workspaceName        | "live"               |
      | workspaceTitle       | "Live"               |
      | workspaceDescription | "The live workspace" |
      | newContentStreamId   | "cs-identifier"      |

    And I am in workspace "live"
    And I am in dimension space point {"language": "de"}
    And I am user identified by "initiating-user-identifier"
    And the command CreateRootNodeAggregateWithNode is executed with payload:
      | Key             | Value                         |
      | nodeAggregateId | "lady-eleonode-rootford"      |
      | nodeTypeName    | "Neos.ContentRepository:Root" |


    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId            | nodeName   | parentNodeAggregateId  | nodeTypeName                        | initialPropertyValues                                              |
      | sir-david-nodenborough     | node       | lady-eleonode-rootford | Neos.ContentRepository.Testing:Node | {}                                                                 |
      | nody-mc-nodeface           | child-node | sir-david-nodenborough | Neos.ContentRepository.Testing:Node | {"text": "This is a text about Nody Mc Nodeface"}                  |
      | sir-nodeward-nodington-iii | esquire    | lady-eleonode-rootford | Neos.ContentRepository.Testing:Node | {"text": "This is a french text about Sir Nodeward Nodington III"} |


    And the command CreateWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | baseWorkspaceName  | "live"           |
      | newContentStreamId | "user-cs-id"     |

  Scenario: Create node specialization variant of node
    When I am in workspace "user-workspace" and dimension space point {"language":"de"}
    And the command CreateNodeVariant is executed with payload:
      | Key             | Value              |
      | nodeAggregateId | "nody-mc-nodeface" |
      | sourceOrigin    | {"language":"de"}  |
      | targetOrigin    | {"language":"gsw"} |

    Then I expect the ChangeProjection to have the following changes in "user-cs-id":
      | nodeAggregateId  | created | changed | moved | deleted | originDimensionSpacePoint |
      | nody-mc-nodeface | 1       | 1       | 0     | 0       | {"language":"gsw"}        |
