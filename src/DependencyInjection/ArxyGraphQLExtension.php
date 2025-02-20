<?php

declare(strict_types=1);

namespace Arxy\GraphQL\DependencyInjection;

use Arxy\GraphQL\CachedDocumentNodeProvider;
use Arxy\GraphQL\Command\DumpSchemaCommand;
use Arxy\GraphQL\Controller\CacheResponseExecutor;
use Arxy\GraphQL\Controller\Executor;
use Arxy\GraphQL\Controller\ExecutorInterface;
use Arxy\GraphQL\Controller\GraphQL;
use Arxy\GraphQL\DocumentNodeProvider;
use Arxy\GraphQL\DocumentNodeProviderInterface;
use Arxy\GraphQL\QueryContainerFactory;
use Arxy\GraphQL\Resolver;
use Arxy\GraphQL\ResolverInterface;
use Arxy\GraphQL\SchemaBuilder;
use Exception;
use ReflectionClass;
use Reflector;
use Sentry\State\HubInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;

final class ArxyGraphQLExtension extends Extension
{
    /**
     * @throws Exception
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $debug = $container->getParameter('kernel.debug');

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.php');

        if ($debug) {
            $loader->load('services_dev.php');
        }

        $hasSentry = $container::willBeAvailable('sentry/sentry', HubInterface::class, ['arxy/graphql-bundle']);

        if ($hasSentry) {
            $loader->load('services_sentry.php');
        }

        $schemas = $config['schema'];
        $schemas[] = __DIR__.'/../Resources/graphql/schema.graphql';
        $schemaBuilderDef = $container->getDefinition(SchemaBuilder::class);
        $schemaBuilderDef->setArgument('$debug', $debug);


        $executableSchemaBuilderDef = $container->getDefinition('arxy.graphql.executable_schema');

        $executableSchemaBuilderDef->setArgument('$enumsMapping', $config['enums_mapping']);
        $executableSchemaBuilderDef->setArgument('$inputObjectsMapping', $config['input_objects_mapping']);
        $executableSchemaBuilderDef->setArgument('$argumentsMapping', $config['arguments_mapping']);

        $cacheResolvers = [];
        foreach ($config['cache_resolvers'] as $objectName => $fields) {
            foreach ($fields as $fieldName => $cacheResolver) {
                $cacheResolvers[$objectName][$fieldName] = new Reference($cacheResolver);
            }
        }

        $executableSchemaBuilderDef->setArgument('$cacheResolvers', $cacheResolvers);

        $container->setParameter('arxy.graphql.middlewares', $config['middlewares']);

        $controllerDef = $container->getDefinition(GraphQL::class);
        $controllerDef->setArgument('$contextFactory', new Reference($config['context_factory']));

        $queryContainerFactoryDef = $container->getDefinition(QueryContainerFactory::class);
        $queryContainerFactoryDef->setArgument('$queryCache', new Reference($config['query_cache']));

        $executionResultCache = $config['operation_execution_result_cache'];

        $executorDef = $container->getDefinition(Executor::class);
        $executorDef->setArgument('$promiseAdapter', new Reference($config['promise_adapter']));
        $executorDef->setArgument('$debug', $debug);
        $executorDef->setArgument('$errorsHandler', new Reference($config['errors_handler']));
        $executorDef->setArgument('$cacheItemPool', new Reference($executionResultCache));

//
//        $cachedExecutorDef = new Definition(CacheResponseExecutor::class);
//        $cachedExecutorDef->setArgument('$executor', new Reference('.inner'));
//        $cachedExecutorDef->setArgument('$cache', new Reference($executionResultCache));
//        $cachedExecutorDef->setAutoconfigured(true);
//        $cachedExecutorDef->setDecoratedService(ExecutorInterface::class);
//
//        $container->setDefinition(CacheResponseExecutor::class, $cachedExecutorDef);


        $dumpSchemaCommand = $container->getDefinition(DumpSchemaCommand::class);
        $dumpSchemaCommand->setArgument('$location', $config['schema_dump_location']);

        $container->registerForAutoconfiguration(ResolverInterface::class)->addTag('arxy.graphql.resolver');

        $documentNodeProvider = $container->getDefinition(DocumentNodeProvider::class);
        $documentNodeProvider->setArgument('$schemas', $schemas);
        $container->setAlias(DocumentNodeProviderInterface::class, DocumentNodeProvider::class);

        if (!$debug) {
            $cachedDocumentNodeProvider = new Definition(CachedDocumentNodeProvider::class);
            $cachedDocumentNodeProvider->setArgument('$documentNodeProvider', new Reference('.inner'));
            $cachedDocumentNodeProvider->setArgument('$cacheFile', $config['cache_dir'].'/schema.php');
            $cachedDocumentNodeProvider->setDecoratedService(DocumentNodeProvider::class);
            $container->setDefinition(CachedDocumentNodeProvider::class, $cachedDocumentNodeProvider);
        }

        $container->registerAttributeForAutoconfiguration(Resolver::class, static function (
            ChildDefinition $definition,
            Resolver $resolver,
            Reflector $reflection
        ): void {
            if (!$reflection instanceof ReflectionClass) {
                return;
            }
            $definition->addTag('arxy.graphql.resolver', ['name' => $resolver->name ?? $reflection->getShortName()]);
        });
    }
}
