<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Arxy\GraphQL\ArgumentMapperMiddleware;
use Arxy\GraphQL\CacheWarmer;
use Arxy\GraphQL\Command\DumpSchemaCommand;
use Arxy\GraphQL\Controller\GraphQL;
use Arxy\GraphQL\DocumentNodeProvider;
use Arxy\GraphQL\DocumentNodeProviderInterface;
use Arxy\GraphQL\ErrorsHandler;
use Arxy\GraphQL\RequestHandler;
use Arxy\GraphQL\SchemaBuilder;
use Arxy\GraphQL\Security\SecurityMiddleware;
use Arxy\GraphQL\StandardServerFactory;
use Arxy\GraphQL\Validator\ValidatorMiddleware;
use GraphQL\Server\StandardServer;
use GraphQL\Type\Schema;
use Psr\Log\LogLevel;

return function (ContainerConfigurator $configurator) {
    $params = $configurator->parameters();
    $params->set('arxy.graphql.error_handler.log_level', LogLevel::ERROR);

    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set(SchemaBuilder::class)->args([
        '$documentNodeProvider' => service(DocumentNodeProviderInterface::class),
    ]);
    $services->set(DocumentNodeProvider::class);
    $services->set(ErrorsHandler::class)
        ->arg('$logLevel', param('arxy.graphql.error_handler.log_level'));

    $services->set('arxy.graphql.executable_schema', Schema::class)
        ->factory([service(SchemaBuilder::class), 'makeExecutableSchema']);

    $services->set(DumpSchemaCommand::class);

    $services->set(RequestHandler::class);

    $services->set(GraphQL::class)
        ->tag('controller.service_arguments')
        ->arg('$schema', service('arxy.graphql.executable_schema'));

    $services->set(CacheWarmer::class);

    $services->set(SecurityMiddleware::class);
    $services->set(ArgumentMapperMiddleware::class);
    $services->set(ValidatorMiddleware::class);
};
