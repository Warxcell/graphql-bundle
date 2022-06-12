<?php

declare(strict_types=1);

namespace Arxy\GraphQL\DependencyInjection;

use Arxy\GraphQL\Controller\GraphQL;
use Arxy\GraphQL\InterfaceResolverInterface;
use Arxy\GraphQL\ResolverInterface;
use Arxy\GraphQL\ScalarResolverInterface;
use Arxy\GraphQL\SchemaBuilder;
use Arxy\GraphQL\UnionResolverInterface;
use Exception;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
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
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.php');

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $schemaBuilderDef = $container->getDefinition(SchemaBuilder::class);
        $schemaBuilderDef->setArgument('$cacheDir', $config['cache_dir']);
        $schemaBuilderDef->setArgument('$debug', $config['debug']);
        $schemaBuilderDef->setArgument('$schemas', $config['schema']);

        $controllerDef = $container->getDefinition(GraphQL::class);
        $controllerDef->setArgument('$promiseAdapter', new Reference($config['promise_adapter']));
        $controllerDef->setArgument('$debug', $config['debug']);
        $controllerDef->setArgument('$contextFactory', new Reference($config['context_factory']));
        $controllerDef->setArgument('$errorsHandler', new Reference($config['errors_handler']));

        $container->registerForAutoconfiguration(ResolverInterface::class)->addTag('arxy.graphql.resolver');
        $container->registerForAutoconfiguration(ScalarResolverInterface::class)->addTag('arxy.graphql.resolver');
        $container->registerForAutoconfiguration(InterfaceResolverInterface::class)->addTag('arxy.graphql.resolver');
        $container->registerForAutoconfiguration(UnionResolverInterface::class)->addTag('arxy.graphql.resolver');
    }
}
