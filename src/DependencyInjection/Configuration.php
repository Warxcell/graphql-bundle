<?php

declare(strict_types=1);

namespace Arxy\GraphQL\DependencyInjection;

use Arxy\GraphQL\ContextFactoryInterface;
use Arxy\GraphQL\ErrorHandler;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function assert;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('arxy_graphql');
        $rootNode = $treeBuilder->getRootNode();
        assert($rootNode instanceof ArrayNodeDefinition);

        // @formatter:off
        $rootNode
            ->children()
                ->arrayNode('schema')
                    ->isRequired()
                    ->scalarPrototype()
                        ->cannotBeEmpty()
                    ->end()
                ->end()
                ->arrayNode('middlewares')
                    ->variablePrototype()
                    ->end()
                ->end()
                ->arrayNode('arguments_mapping')
                    ->useAttributeAsKey('__object')
                    ->arrayPrototype()
                        ->useAttributeAsKey('__field')
                        ->scalarPrototype()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('enums_mapping')
                    ->useAttributeAsKey('__name')
                    ->scalarPrototype()
                    ->end()
                ->end()
                ->arrayNode('input_objects_mapping')
                    ->useAttributeAsKey('__name')
                    ->scalarPrototype()
                    ->end()
                ->end()
                ->scalarNode('debug')
                    ->defaultValue('%kernel.debug%')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('context_factory')
                    ->defaultValue(ContextFactoryInterface::class)
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('errors_handler')
                    ->defaultValue(ErrorHandler::class)
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('cache_dir')
                    ->defaultValue('%kernel.build_dir%/arxy_graphql')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('promise_adapter')
                    ->defaultValue('webonyx_graphql.sync_promise_adapter')
                    ->cannotBeEmpty()
                ->end()
            ->end();
        // @formatter:on
        return $treeBuilder;
    }
}
