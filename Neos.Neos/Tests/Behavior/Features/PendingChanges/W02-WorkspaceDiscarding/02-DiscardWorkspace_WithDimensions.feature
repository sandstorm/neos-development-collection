@contentrepository @adapters=DoctrineDBAL
@flowEntities
Feature: Discard workspace with dimensions

  Background:
    Given using the following content dimensions:
      | Identifier | Values       | Generalizations |
      | language   | de,gsw,fr,en | gsw->de->en, fr |
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
      | nodeAggregateId        | nodeName | parentNodeAggregateId  | nodeTypeName                        | initialPropertyValues |
      | sir-david-nodenborough | node     | lady-eleonode-rootford | Neos.ContentRepository.Testing:Node | {}                    |

  Scenario: Discard user workspace
    Given the command CreateWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | baseWorkspaceName  | "live"           |
      | newContentStreamId | "user-cs-id"     |
    And I am in workspace "user-workspace"

    Then I am in dimension space point {"language": "de"}
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId           | nodeName   | parentNodeAggregateId  | nodeTypeName                        | initialPropertyValues                                      |
      | nody-mc-nodeface          | child-node | sir-david-nodenborough | Neos.ContentRepository.Testing:Node | {"text": "This is a text about Nody Mc Nodeface"}          |
      | sir-nodeward-nodington-iv | bakura     | lady-eleonode-rootford | Neos.ContentRepository.Testing:Node | {"text": "This is a text about Sir Nodeward Nodington IV"} |

    Then I am in dimension space point {"language": "fr"}
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId            | nodeName | parentNodeAggregateId  | nodeTypeName                        | initialPropertyValues                                                |
      | sir-nodeward-nodington-iii | esquire  | lady-eleonode-rootford | Neos.ContentRepository.Testing:Node | {"text": "This is a extended text about Sir Nodeward Nodington III"} |

    Then I expect the ChangeProjection to have the following changes in "user-cs-id":
      | nodeAggregateId            | created | changed | moved | deleted | originDimensionSpacePoint |
      | nody-mc-nodeface           | 1       | 1       | 0     | 0       | {"language":"de"}         |
      | sir-nodeward-nodington-iv  | 1       | 1       | 0     | 0       | {"language":"de"}         |
      | sir-nodeward-nodington-iii | 1       | 1       | 0     | 0       | {"language":"fr"}         |

    When the command DiscardWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "new-user-cs-id" |

    Then I expect the ChangeProjection to have no changes in "user-cs-id"
    And I expect the ChangeProjection to have no changes in "new-user-cs-id"


  Scenario: Discard user workspace with a non-live base workspace
    Given the command CreateWorkspace is executed with payload:
      | Key                | Value              |
      | workspaceName      | "review-workspace" |
      | baseWorkspaceName  | "live"             |
      | newContentStreamId | "review-cs-id"     |

    And I am in workspace "review-workspace"

    Then I am in dimension space point {"language": "de"}
    And  the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId  | nodeName   | parentNodeAggregateId  | nodeTypeName                        | initialPropertyValues                             |
      | nody-mc-nodeface | child-node | sir-david-nodenborough | Neos.ContentRepository.Testing:Node | {"text": "This is a text about Nody Mc Nodeface"} |

    And the command CreateWorkspace is executed with payload:
      | Key                | Value              |
      | workspaceName      | "user-workspace"   |
      | baseWorkspaceName  | "review-workspace" |
      | newContentStreamId | "user-cs-id"       |

    And I am in workspace "user-workspace"

    Then I am in dimension space point {"language": "de"}
    And  the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId           | nodeName | parentNodeAggregateId  | nodeTypeName                        | initialPropertyValues                                      |
      | sir-nodeward-nodington-iv | bakura   | lady-eleonode-rootford | Neos.ContentRepository.Testing:Node | {"text": "This is a text about Sir Nodeward Nodington IV"} |

    Then I am in dimension space point {"language": "gsw"}
    And the command CreateNodeVariant is executed with payload:
      | Key             | Value              |
      | nodeAggregateId | "nody-mc-nodeface" |
      | sourceOrigin    | {"language":"de"}  |
      | targetOrigin    | {"language":"gsw"} |

    And the command SetNodeProperties is executed with payload:
      | Key                       | Value                       |
      | nodeAggregateId           | "nody-mc-nodeface"          |
      | originDimensionSpacePoint | {"language":"gsw"}          |
      | propertyValues            | {"text": "Some other text"} |

    And I am in dimension space point {"language": "fr"}
    Then the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId            | nodeName | parentNodeAggregateId  | nodeTypeName                        | initialPropertyValues                                                |
      | sir-nodeward-nodington-iii | esquire  | lady-eleonode-rootford | Neos.ContentRepository.Testing:Node | {"text": "This is a extended text about Sir Nodeward Nodington III"} |

    Then I expect the ChangeProjection to have the following changes in "user-cs-id":
      | nodeAggregateId            | created | changed | moved | deleted | originDimensionSpacePoint |
      | nody-mc-nodeface           | 1       | 1       | 0     | 0       | {"language":"gsw"}        |
      | sir-nodeward-nodington-iv  | 1       | 1       | 0     | 0       | {"language":"de"}         |
      | sir-nodeward-nodington-iii | 1       | 1       | 0     | 0       | {"language":"fr"}         |
    Then I expect the ChangeProjection to have the following changes in "review-cs-id":
      | nodeAggregateId  | created | changed | moved | deleted | originDimensionSpacePoint |
      | nody-mc-nodeface | 1       | 1       | 0     | 0       | {"language":"de"}         |

    When the command DiscardWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "new-user-cs-id" |

    Then I expect the ChangeProjection to have no changes in "user-cs-id"
    And I expect the ChangeProjection to have no changes in "new-user-cs-id"
    And I expect the ChangeProjection to have the following changes in "review-cs-id":
      | nodeAggregateId  | created | changed | moved | deleted | originDimensionSpacePoint |
      | nody-mc-nodeface | 1       | 1       | 0     | 0       | {"language":"de"}         |

  Scenario: Discard user workspace with new generalization
    Given the command CreateWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | baseWorkspaceName  | "live"           |
      | newContentStreamId | "user-cs-id"     |
    And I am in workspace "user-workspace"

    Then I am in dimension space point {"language": "de"}
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId           | nodeName   | parentNodeAggregateId  | nodeTypeName                        | initialPropertyValues                                      |
      | nody-mc-nodeface          | child-node | sir-david-nodenborough | Neos.ContentRepository.Testing:Node | {"text": "This is a text about Nody Mc Nodeface"}          |
      | sir-nodeward-nodington-iv | bakura     | lady-eleonode-rootford | Neos.ContentRepository.Testing:Node | {"text": "This is a text about Sir Nodeward Nodington IV"} |

    And the command CreateNodeVariant is executed with payload:
      | Key             | Value                    |
      | nodeAggregateId | "sir-david-nodenborough" |
      | sourceOrigin    | {"language":"de"}        |
      | targetOrigin    | {"language":"en"}        |

    Then I expect the ChangeProjection to have the following changes in "user-cs-id":
      | nodeAggregateId           | created | changed | moved | deleted | originDimensionSpacePoint |
      | nody-mc-nodeface          | 1       | 1       | 0     | 0       | {"language":"de"}         |
      | sir-nodeward-nodington-iv | 1       | 1       | 0     | 0       | {"language":"de"}         |
      | sir-david-nodenborough    | 1       | 1       | 0     | 0       | {"language":"en"}         |

    When the command DiscardWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "new-user-cs-id" |

    Then I expect the ChangeProjection to have no changes in "user-cs-id"
    And I expect the ChangeProjection to have no changes in "new-user-cs-id"

  Scenario: Discard user workspace after change to an existing asseet usage of a property
    Given the command CreateWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | baseWorkspaceName  | "live"           |
      | newContentStreamId | "user-cs-id"     |
    And I am in workspace "user-workspace"

    Then I am in dimension space point {"language": "de"}

    And the command SetNodeProperties is executed with payload:
      | Key                       | Value                    |
      | nodeAggregateId           | "sir-david-nodenborough" |
      | originDimensionSpacePoint | {"language":"de"}        |
      | propertyValues            | {"text": "New text"}     |

    Then I expect the ChangeProjection to have the following changes in "user-cs-id":
      | nodeAggregateId        | created | changed | moved | deleted | originDimensionSpacePoint |
      | sir-david-nodenborough | 0       | 1       | 0     | 0       | {"language":"de"}         |

    When the command DiscardWorkspace is executed with payload:
      | Key                | Value            |
      | workspaceName      | "user-workspace" |
      | newContentStreamId | "new-user-cs-id" |

    Then I expect the ChangeProjection to have no changes in "user-cs-id"
    And I expect the ChangeProjection to have no changes in "new-user-cs-id"
