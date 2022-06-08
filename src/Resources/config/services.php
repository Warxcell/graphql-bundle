<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Arxy\GraphQL\Controller\GraphQL;

return function (ContainerConfigurator $configurator) {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('Arxy\\GraphQL\\', '../../*')
        ->exclude('../../{Resources,DependencyInjection}')
        ->bind('$resolverMaps', tagged_iterator('arxy.graphql.resolver_map'))
        ->bind('$plugins', tagged_iterator('arxy.graphql.plugin'));

    $services->set(GraphQL::class)
        ->tag('controller.service_arguments')
        ->arg('$plugins', tagged_iterator('arxy.graphql.plugin'));
};
