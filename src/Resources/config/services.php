<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Arxy\GraphQL\Controller\GraphQL;
use Arxy\GraphQL\SchemaBuilder;
use Arxy\GraphQL\Serializer\BackedEnumNormalizer;
use GraphQL\Type\Schema;

return function (ContainerConfigurator $configurator) {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('Arxy\\GraphQL\\', '../../*')
        ->exclude('../../{Resources,DependencyInjection}');

    $services->set('arxy.graphql.executable_schema', Schema::class)
        ->factory([service(SchemaBuilder::class), 'makeExecutableSchema']);

    $services->set(GraphQL::class)
        ->tag('controller.service_arguments')
        ->arg('$schema', service('arxy.graphql.executable_schema'));

    $services->set(BackedEnumNormalizer::class)
        ->decorate('serializer.normalizer.backed_enum')
        ->args(['$decorated' => service('.inner')]);
};
