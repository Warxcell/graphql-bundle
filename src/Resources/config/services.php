<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Arxy\GraphQL\CacheWarmer;
use Arxy\GraphQL\Command\DumpSchemaCommand;
use Arxy\GraphQL\Controller\Executor;
use Arxy\GraphQL\Controller\ExecutorInterface;
use Arxy\GraphQL\Controller\GraphQL;
use Arxy\GraphQL\DocumentNodeProvider;
use Arxy\GraphQL\DocumentNodeProviderInterface;
use Arxy\GraphQL\ErrorsHandler;
use Arxy\GraphQL\QueryContainerFactory;
use Arxy\GraphQL\QueryContainerFactoryInterface;
use Arxy\GraphQL\QueryValidator;
use Arxy\GraphQL\RequestHandler;
use Arxy\GraphQL\SchemaBuilder;
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

    $services->alias(Schema::class, 'arxy.graphql.executable_schema');

    $services->set(DumpSchemaCommand::class);

    $services->set(RequestHandler::class);

    $services->set(QueryContainerFactory::class);
    $services->alias(QueryContainerFactoryInterface::class, QueryContainerFactory::class);

    $services->set(Executor::class);
    $services->alias(ExecutorInterface::class, Executor::class);

    $services->set(GraphQL::class)
        ->tag('controller.service_arguments');

    $services->set(CacheWarmer::class);

    $services->set(QueryValidator::class);
};
