<?php

declare(strict_types=1);

namespace Arxy\GraphQL\DependencyInjection;

use Psr\Log\LoggerInterface;
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
                ->scalarNode('debug')
                    ->defaultValue('%kernel.debug%')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('logger')
                    ->defaultValue(LoggerInterface::class)
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
