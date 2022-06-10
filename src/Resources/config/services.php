<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Arxy\GraphQL\Command\Codegen;
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

    $services->set(SchemaBuilder::class)
        ->arg('$modules', tagged_iterator('arxy.graphql.module'));

    $services->set(Codegen::class)
        ->arg('$modules', tagged_iterator('arxy.graphql.module'));

    $services->set('arxy.graphql.executable_schema', Schema::class)
        ->factory([service(SchemaBuilder::class), 'makeExecutableSchema'])
        ->args([
            '$plugins' => tagged_iterator('arxy.graphql.plugin'),
        ]);

    $services->set(GraphQL::class)
        ->tag('controller.service_arguments')
        ->arg('$plugins', tagged_iterator('arxy.graphql.plugin'))
        ->arg('$schema', service('arxy.graphql.executable_schema'));

    $services->set(BackedEnumNormalizer::class)
        ->decorate('serializer.normalizer.backed_enum')
        ->args(['$decorated' => service('.inner')]);
};
