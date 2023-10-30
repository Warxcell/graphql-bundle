<?php

declare(strict_types=1);

namespace Arxy\GraphQL\DependencyInjection;

use Arxy\GraphQL\ArgumentMapperMiddleware;
use Arxy\GraphQL\CachedDocumentNodeProvider;
use Arxy\GraphQL\Command\DumpSchemaCommand;
use Arxy\GraphQL\DocumentNodeProvider;
use Arxy\GraphQL\DocumentNodeProviderInterface;
use Arxy\GraphQL\Resolver;
use Arxy\GraphQL\ResolverInterface;
use Arxy\GraphQL\SchemaBuilder;
use Exception;
use GraphQL\Server\StandardServer;
use ReflectionClass;
use Reflector;
use Symfony\Component\Config\FileLocator;
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

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.php');
        if ($debug) {
            $loader->load('services_dev.php');
        }

        $schemas = $config['schema'];
        $schemas[] = __DIR__ . '/../Resources/graphql/schema.graphql';
        $schemaBuilderDef = $container->getDefinition(SchemaBuilder::class);
        $schemaBuilderDef->setArgument('$debug', $debug);

        $executableSchemaBuilderDef = $container->getDefinition('arxy.graphql.executable_schema');

        $executableSchemaBuilderDef->setArgument('$enumsMapping', $config['enums_mapping']);
        $executableSchemaBuilderDef->setArgument('$inputObjectsMapping', $config['input_objects_mapping']);

        $argumentsMapperMiddlewareDef = $container->getDefinition(ArgumentMapperMiddleware::class);
        $argumentsMapperMiddlewareDef->setArgument('$argumentsMapping', $config['arguments_mapping']);

        $container->setParameter('arxy.graphql.middlewares', $config['middlewares']);

        $controllerDef = $container->getDefinition(StandardServer::class);
        $controllerDef->setArgument('$promiseAdapter', new Reference($config['promise_adapter']));
        $controllerDef->setArgument('$debug', $debug);
        $controllerDef->setArgument('$contextFactory', new Reference($config['context_factory']));
        $controllerDef->setArgument('$errorsHandler', new Reference($config['errors_handler']));

        $dumpSchemaCommand = $container->getDefinition(DumpSchemaCommand::class);
        $dumpSchemaCommand->setArgument('$location', $config['schema_dump_location']);

        $container->registerForAutoconfiguration(ResolverInterface::class)->addTag('arxy.graphql.resolver');

        $documentNodeProvider = $container->getDefinition(DocumentNodeProvider::class);
        $documentNodeProvider->setArgument('$schemas', $schemas);
        $container->setAlias(DocumentNodeProviderInterface::class, DocumentNodeProvider::class);

        if (!$debug) {
            $cachedDocumentNodeProvider = new Definition(CachedDocumentNodeProvider::class);
            $cachedDocumentNodeProvider->setArgument('$documentNodeProvider', new Reference('.inner'));
            $cachedDocumentNodeProvider->setArgument('$cacheFile', $config['cache_dir'] . '/schema.php');
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
