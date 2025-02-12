<?php

declare(strict_types=1);

namespace Neos\ContentRepository\NodeMigration;

use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryDependencies;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryInterface;
use Neos\ContentRepository\NodeMigration\Filter\DimensionSpacePointsFilterFactory;
use Neos\ContentRepository\NodeMigration\Filter\FiltersFactory;
use Neos\ContentRepository\NodeMigration\Filter\NodeNameFilterFactory;
use Neos\ContentRepository\NodeMigration\Filter\NodeTypeFilterFactory;
use Neos\ContentRepository\NodeMigration\Filter\PropertyNotEmptyFilterFactory;
use Neos\ContentRepository\NodeMigration\Filter\PropertyValueFilterFactory;
use Neos\ContentRepository\NodeMigration\Transformation\AddDimensionShineThroughTransformationFactory;
use Neos\ContentRepository\NodeMigration\Transformation\AddNewPropertyConverterAwareTransformationFactory;
use Neos\ContentRepository\NodeMigration\Transformation\ChangeNodeTypeTransformationFactory;
use Neos\ContentRepository\NodeMigration\Transformation\ChangePropertyValueConverterAwareTransformationFactory;
use Neos\ContentRepository\NodeMigration\Transformation\MoveDimensionSpacePointTransformationFactory;
use Neos\ContentRepository\NodeMigration\Transformation\RemoveNodeTransformationFactory;
use Neos\ContentRepository\NodeMigration\Transformation\RemovePropertyTransformationFactory;
use Neos\ContentRepository\NodeMigration\Transformation\RenameNodeAggregateTransformationFactory;
use Neos\ContentRepository\NodeMigration\Transformation\RenamePropertyTransformationFactory;
use Neos\ContentRepository\NodeMigration\Transformation\StripTagsOnPropertyTransformationFactory;
use Neos\ContentRepository\NodeMigration\Transformation\TransformationsFactory;
use Neos\ContentRepository\NodeMigration\Transformation\UpdateRootNodeAggregateDimensionsTransformationFactory;

/**
 * @implements ContentRepositoryServiceFactoryInterface<NodeMigrationService>
 */
class NodeMigrationServiceFactory implements ContentRepositoryServiceFactoryInterface
{
    public function build(ContentRepositoryServiceFactoryDependencies $serviceFactoryDependencies): NodeMigrationService
    {
        $filtersFactory = new FiltersFactory($serviceFactoryDependencies->contentRepository);
        $filtersFactory->registerFilter('DimensionSpacePoints', new DimensionSpacePointsFilterFactory());
        $filtersFactory->registerFilter('NodeName', new NodeNameFilterFactory());
        $filtersFactory->registerFilter('NodeType', new NodeTypeFilterFactory());
        $filtersFactory->registerFilter('PropertyNotEmpty', new PropertyNotEmptyFilterFactory());
        $filtersFactory->registerFilter('PropertyValue', new PropertyValueFilterFactory());

        $transformationsFactory = new TransformationsFactory($serviceFactoryDependencies->contentRepository, $serviceFactoryDependencies->propertyConverter);
        $transformationsFactory->registerTransformation('AddDimensionShineThrough', new AddDimensionShineThroughTransformationFactory());
        $transformationsFactory->registerTransformation('AddNewProperty', new AddNewPropertyConverterAwareTransformationFactory());
        $transformationsFactory->registerTransformation('ChangeNodeType', new ChangeNodeTypeTransformationFactory());
        $transformationsFactory->registerTransformation('ChangePropertyValue', new ChangePropertyValueConverterAwareTransformationFactory());
        $transformationsFactory->registerTransformation('MoveDimensionSpacePoint', new MoveDimensionSpacePointTransformationFactory());
        $transformationsFactory->registerTransformation('RemoveNode', new RemoveNodeTransformationFactory());
        $transformationsFactory->registerTransformation('RemoveProperty', new RemovePropertyTransformationFactory());
        $transformationsFactory->registerTransformation('RenameNodeAggregate', new RenameNodeAggregateTransformationFactory());
        $transformationsFactory->registerTransformation('RenameProperty', new RenamePropertyTransformationFactory());
        $transformationsFactory->registerTransformation('StripTagsOnProperty', new StripTagsOnPropertyTransformationFactory());
        $transformationsFactory->registerTransformation('UpdateRootNodeAggregateDimensions', new UpdateRootNodeAggregateDimensionsTransformationFactory());

        return new NodeMigrationService(
            $serviceFactoryDependencies->contentRepository,
            $filtersFactory,
            $transformationsFactory
        );
    }
}
