<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Arxy\GraphQL\Command\DumpSchemaCommand;
use Arxy\GraphQL\Controller\GraphQL;
use Arxy\GraphQL\DocumentNodeProvider;
use Arxy\GraphQL\DocumentNodeProviderInterface;
use Arxy\GraphQL\ErrorHandler;
use Arxy\GraphQL\RequestHandler;
use Arxy\GraphQL\SchemaBuilder;
use Arxy\GraphQL\StandardServerFactory;
use GraphQL\Server\StandardServer;
use GraphQL\Type\Schema;

return function (ContainerConfigurator $configurator) {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set(SchemaBuilder::class)->args([
        '$documentNodeProvider' => service(DocumentNodeProviderInterface::class),
    ]);
    $services->set(DocumentNodeProvider::class);
    $services->set(ErrorHandler::class);

    $services->set('arxy.graphql.executable_schema', Schema::class)
        ->factory([service(SchemaBuilder::class), 'makeExecutableSchema']);

    $services->set(DumpSchemaCommand::class);

    $services->set(RequestHandler::class);
    $services->set(StandardServer::class)
        ->factory([StandardServerFactory::class, 'factory'])
        ->arg('$schema', service('arxy.graphql.executable_schema'));

    $services->set(GraphQL::class)
        ->tag('controller.service_arguments');
};
