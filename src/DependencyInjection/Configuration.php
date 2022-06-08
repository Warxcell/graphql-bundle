<?php

declare(strict_types=1);

namespace Arxy\GraphQL\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

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
                    ->scalarPrototype()->end()
                    ->isRequired()
                ->end()
                ->scalarNode('debug')
                    ->defaultValue('%kernel.debug%')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('cache_dir')
                    ->defaultValue('%kernel.build_dir%/arxy_graphql')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('promise_adapter')
                    ->cannotBeEmpty()
                ->end()
            ->end();
        // @formatter:on
        return $treeBuilder;
    }
}
