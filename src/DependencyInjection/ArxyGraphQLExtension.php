<?php

declare(strict_types=1);

namespace Arxy\GraphQL\DependencyInjection;

use Arxy\GraphQL\Controller\GraphQL;
use Arxy\GraphQL\Module;
use Arxy\GraphQL\Plugin;
use Arxy\GraphQL\Resolver;
use Arxy\GraphQL\SchemaBuilder;
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

        $controllerDef = $container->getDefinition(GraphQL::class);
        $controllerDef->setArgument('$promiseAdapter', new Reference($config['promise_adapter']));
        $controllerDef->setArgument('$debug', $config['debug']);
        $controllerDef->setArgument('$logger', new Reference($config['logger']));

        $container->registerForAutoconfiguration(Module::class)->addTag('arxy.graphql.module');
        $container->registerForAutoconfiguration(Plugin::class)->addTag('arxy.graphql.plugin');
        $container->registerForAutoconfiguration(Resolver::class)->addTag('arxy.graphql.resolver');
    }
}
