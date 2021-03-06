<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use Arxy\GraphQL\Debug\TimingMiddleware;
use Closure;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use LogicException;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\Bundle;

use function array_reverse;
use function count;
use function in_array;
use function is_int;
use function sprintf;
use function str_replace;

final class ArxyGraphQLBundle extends Bundle
{
    public function build(ContainerBuilder $container)
    {
        $container->addCompilerPass(
            new class implements CompilerPassInterface {
                public function process(ContainerBuilder $container)
                {
                    $schemaBuilder = $container->get(SchemaBuilder::class);
                    $schema = $schemaBuilder->makeSchema();

                    $debug = $container->getParameter('kernel.debug');

                    $executableSchemaBuilderDefinition = $container->getDefinition('arxy.graphql.executable_schema');
                    $resolvers = [];

                    $resolversDebugInfo = [];

                    foreach ($container->findTaggedServiceIds('arxy.graphql.resolver') as $serviceId => $tags) {
                        $service = $container->getDefinition($serviceId);

                        $reflection = $container->getReflectionClass($service->getClass());

                        $resolveName = static function () use ($reflection): string {
                            $implements = $reflection->getInterfaces();
                            foreach ($implements as $interface) {
                                if ($interface->implementsInterface(ResolverInterface::class)) {
                                    return str_replace('ResolverInterface', '', $interface->getShortName());
                                }
                            }
                            throw new LogicException(
                                sprintf('Could not determine graphql object name for %s', $reflection->getName())
                            );
                        };
                        foreach ($tags as $tag) {
                            $graphqlName = $tag['name'] ?? $resolveName();

                            $type = $schema->getType($graphqlName);

                            switch (true) {
                                case $type instanceof ScalarType:
                                case $type instanceof UnionType:
                                case $type instanceof InterfaceType:
                                    $resolvers[$graphqlName] = new Reference($serviceId);
                                    break;
                                case $type instanceof ObjectType:
                                    $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

                                    foreach ($methods as $method) {
                                        if ($method->isConstructor()) {
                                            continue;
                                        }

                                        $resolverInfo = [];
                                        $field = $method->getName();

                                        if (isset($resolvers[$graphqlName][$field])) {
                                            throw new LogicException(
                                                sprintf('Multiple resolvers exists for %s.%s', $graphqlName, $field)
                                            );
                                        }

                                        $resolver = [new Reference($serviceId), $field];
                                        $resolverInfo[] = [$serviceId, $field];

                                        $actuallyWrapResolver = function ($resolver, $serviceId) use (
                                            $container,
                                            $graphqlName,
                                            $field
                                        ) {
                                            $middlewareDefId = sprintf('arxy.graphql.middleware.%s.%s.%s', $graphqlName, $field, $serviceId);
                                            $middlewareDef = new Definition(
                                                Closure::class,
                                                [
                                                    '$next' => new Reference($serviceId),
                                                    '$original' => $resolver,
                                                ]
                                            );
                                            $middlewareDef->setFactory([MiddlewareStack::class, 'wrap']);

                                            $container->setDefinition($middlewareDefId, $middlewareDef);

                                            return new Reference($middlewareDefId);
                                        };

                                        $wrapResolver = function ($middlewareId) use (
                                            $container,
                                            $graphqlName,
                                            $field,
                                            &$resolver,
                                            $debug,
                                            &$actuallyWrapResolver,
                                            &$resolverInfo
                                        ): void {
                                            $resolverInfo[] = $middlewareId;
                                            $resolver = $actuallyWrapResolver($resolver, $middlewareId);
                                        };

                                        $middlewares = array_reverse($container->getParameter('arxy.graphql.middlewares'));

                                        if ($debug) {
                                            $middlewares[] = TimingMiddleware::class;
                                        }

                                        foreach ($middlewares as $graphqlNameOrInt => $middlewareOrFields) {
                                            if (is_int($graphqlNameOrInt)) {
                                                $wrapResolver($middlewareOrFields);
                                                continue;
                                            }
                                            if ($graphqlNameOrInt !== $graphqlName) {
                                                continue;
                                            }
                                            foreach (array_reverse($middlewareOrFields) as $fieldOrInt => $middlewareOrField) {
                                                if (is_int($fieldOrInt)) {
                                                    $wrapResolver($middlewareOrField);
                                                } elseif ($fieldOrInt === $field) {
                                                    foreach (array_reverse($middlewareOrField) as $middleware) {
                                                        $wrapResolver($middleware);
                                                    }
                                                }
                                            }
                                        }
                                        $resolvers[$graphqlName][$field] = $resolver;

                                        $resolversDebugInfo[$graphqlName][$field] = array_reverse($resolverInfo);
                                    }
                                    break;
                                default:
                                    throw new LogicException(sprintf('Type %s not supported', $graphqlName));
                            }
                        }
                    }
                    $executableSchemaBuilderDefinition->setArgument('$resolvers', $resolvers);

                    $missingResolvers = [];
                    foreach ($schema->getTypeMap() as $type) {
                        if (in_array($type, Type::getAllBuiltInTypes())) {
                            // standard types have built-in resolvers.
                            continue;
                        }

                        switch (true) {
                            case $type instanceof ScalarType:
                            case $type instanceof UnionType:
                            case $type instanceof InterfaceType:
                                if (!isset($resolvers[$type->name])) {
                                    $missingResolvers[] = sprintf('%s', $type->name);
                                }
                                break;
                            case $type instanceof ObjectType:
                                foreach ($type->getFieldNames() as $field) {
                                    if (!isset($resolvers[$type->name][$field])) {
                                        $missingResolvers[] = sprintf('%s.%s', $type->name, $field);
                                    }
                                }
                                break;
                        }
                    }

                    if (count($missingResolvers) > 0) {
                        throw new LogicException(sprintf('Missing resolvers: %s', implode(', ', $missingResolvers)));
                    }

                    if ($debug) {
                        $debugResolvers = $container->getDefinition(Command\DebugResolversCommand::class);
                        $debugResolvers->setArgument('$resolversInfo', $resolversDebugInfo);
                    }
                }
            }
        );
    }
}
