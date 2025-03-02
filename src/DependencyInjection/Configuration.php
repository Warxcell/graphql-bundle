<?php

declare(strict_types=1);

namespace Arxy\GraphQL\DependencyInjection;

use Arxy\GraphQL\Controller\ContextFactoryInterface;
use Arxy\GraphQL\ErrorsHandler;
use GraphQL\Executor\Promise\PromiseAdapter;
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
                ->scalarNode('context_factory')
                    ->defaultValue(ContextFactoryInterface::class)
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('errors_handler')
                    ->defaultValue(ErrorsHandler::class)
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('query_cache')
                      ->isRequired()
                ->end()
                ->scalarNode('query_hash_algo')
                      ->defaultValue('md5')
                ->end()
                ->scalarNode('operation_execution_result_cache')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('cache_dir')
                    ->defaultValue('%kernel.build_dir%/arxy_graphql')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('schema_dump_location')
                    ->defaultNull()
                ->end()
                ->scalarNode('promise_adapter')
                    ->defaultValue(PromiseAdapter::class)
                    ->cannotBeEmpty()
                ->end()
                ->arrayNode('cache_resolvers')
                    ->useAttributeAsKey('__object')
                    ->arrayPrototype()
                        ->useAttributeAsKey('__field')
                            ->scalarPrototype()
                        ->end()
                    ->end()
                ->end()
            ->end();
        // @formatter:on
        return $treeBuilder;
    }
}
