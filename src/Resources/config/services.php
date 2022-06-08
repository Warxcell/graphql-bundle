<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Arxy\GraphQL\Controller\GraphQL;
use Arxy\GraphQL\SchemaBuilder;
use GraphQL\Type\Schema;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

return function (ContainerConfigurator $configurator) {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('Arxy\\GraphQL\\', '../../*')
        ->exclude('../../{Resources,DependencyInjection}');

    $services->set(SchemaBuilder::class);

    $services->set('arxy.graphql.executable_schema', Schema::class)
        ->factory([service(SchemaBuilder::class), 'makeExecutableSchema'])
        ->args([
            '$resolverMaps' => tagged_iterator('arxy.graphql.resolver_map'),
            '$plugins' => tagged_iterator('arxy.graphql.plugin'),
            '$propertyAccessor' => service(PropertyAccessorInterface::class),
        ]);

    $services->set(GraphQL::class)
        ->tag('controller.service_arguments')
        ->arg('$plugins', tagged_iterator('arxy.graphql.plugin'))
        ->arg('$schema', service('arxy.graphql.executable_schema'));
};
