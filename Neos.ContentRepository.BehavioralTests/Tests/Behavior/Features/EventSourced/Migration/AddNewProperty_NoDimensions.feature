@contentrepository @adapters=DoctrineDBAL
Feature: Add New Property

  Background:
    Given using no content dimensions
    And using the following node types:
    """yaml
    'Neos.ContentRepository:Root':
      constraints:
        nodeTypes:
          'Neos.ContentRepository.Testing:Document': true
    'Neos.ContentRepository.Testing:Document':
      properties:
        text:
          type: string
        dateTime:
          type: DateTime
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
      | originDimensionSpacePoint | {}                                        |
      | parentNodeAggregateId     | "lady-eleonode-rootford"                  |
      | initialPropertyValues     | {"text": "Original text"}                 |

    # Node /doc2
    When the command CreateNodeAggregateWithNode is executed with payload:
      | Key                       | Value                                     |
      | nodeAggregateId           | "other"                                   |
      | nodeTypeName              | "Neos.ContentRepository.Testing:Document" |
      | originDimensionSpacePoint | {}                                        |
      | parentNodeAggregateId     | "lady-eleonode-rootford"                  |
      | initialPropertyValues     | {}                                        |


  Scenario: Fixed newValue
    When I run the following node migration for workspace "live", creating target workspace "migration-workspace" on contentStreamId "migration-cs", without publishing on success:
    """yaml
    migration:
      -
        filters:
          -
            type: 'NodeType'
            settings:
              nodeType: 'Neos.ContentRepository.Testing:Document'
        transformations:
          -
            type: 'AddNewProperty'
            settings:
              newPropertyName: 'text'
              serializedValue: 'fixed value'
              type: 'string'
          -
            type: 'AddNewProperty'
            settings:
              newPropertyName: 'dateTime'
              serializedValue: '2013-09-09T12:04:12+00:00'
              type: 'DateTime'
    """
    # the original content stream has not been touched
    When I am in workspace "live" and dimension space point {}
    Then I expect a node identified by cs-identifier;sir-david-nodenborough;{} to exist in the content graph
    And I expect this node to have the following properties:
      | Key  | Value           |
      | text | "Original text" |

    When I am in workspace "migration-workspace" and dimension space point {}
    Then I expect a node identified by migration-cs;sir-david-nodenborough;{} to exist in the content graph
    And I expect this node to have the following properties:
      | Key  | Value           |
      | text | "Original text" |
    Then I expect a node identified by migration-cs;other;{} to exist in the content graph
    And I expect this node to have the following properties:
      | Key      | Value                          |
      | text     | "fixed value"                  |
      | dateTime | Date:2013-09-09T12:04:12+00:00 |

  Scenario: Adding a property that is not defined in the node type schema
    When I run the following node migration for workspace "live", creating target workspace "migration-workspace" on contentStreamId "migration-cs" and exceptions are caught:
    """yaml
    migration:
      -
        filters:
          -
            type: 'NodeType'
            settings:
              nodeType: 'Neos.ContentRepository.Testing:Document'
        transformations:
          -
            type: 'AddNewProperty'
            settings:
              newPropertyName: 'aDateOutsideSchema'
              serializedValue: '2013-09-09T12:04:12+00:00'
              type: 'DateTime'
    """
    Then the last command should have thrown an exception of type "PropertyCannotBeSet"

  Scenario: Adding a property with a different type than defined by the node type schema
    When I run the following node migration for workspace "live", creating target workspace "migration-workspace" on contentStreamId "migration-cs" and exceptions are caught:
    """yaml
    migration:
      -
        filters:
          -
            type: 'NodeType'
            settings:
              nodeType: 'Neos.ContentRepository.Testing:Document'
        transformations:
          -
            type: 'AddNewProperty'
            settings:
              newPropertyName: 'dateTime'
              serializedValue: '2013-09-09T12:04:12+00:00'
              type: 'string'
    """
    Then the last command should have thrown an exception of type "PropertyCannotBeSet"
