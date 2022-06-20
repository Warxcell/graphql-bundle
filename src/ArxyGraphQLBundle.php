<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use Arxy\GraphQL\Debug\TimingMiddleware;
use Closure;
use LogicException;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\Bundle;

use function array_reverse;
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
                    $debug = $container->getParameter('kernel.debug');

                    $schemaBuilder = $container->getDefinition('arxy.graphql.executable_schema');
                    $resolvers = [];

                    $resolversDebugInfo = [];

                    foreach ($container->findTaggedServiceIds('arxy.graphql.resolver') as $serviceId => $tags) {
                        $service = $container->getDefinition($serviceId);

                        $reflection = $container->getReflectionClass($service->getClass());

                        $implements = $reflection->getInterfaces();

                        $interface = null;
                        foreach ($implements as $interface) {
                            if (
                                $interface->implementsInterface(ResolverInterface::class)
                                || $interface->implementsInterface(ScalarResolverInterface::class)
                                || $interface->implementsInterface(UnionResolverInterface::class)
                                || $interface->implementsInterface(InterfaceResolverInterface::class)
                            ) {
                                break;
                            }
                        }
                        if (!$interface) {
                            throw new LogicException(
                                sprintf('Could not determine graphql object name for %s', $reflection->getName())
                            );
                        }

                        $graphqlName = $tags['name'] ?? str_replace('ResolverInterface', '', $interface->getShortName());

                        if ($reflection->implementsInterface(ScalarResolverInterface::class)) {
                            $resolvers[$graphqlName] = new Reference($serviceId);
                        } elseif ($reflection->implementsInterface(UnionResolverInterface::class)
                            || $reflection->implementsInterface(InterfaceResolverInterface::class)) {
                            $resolvers[$graphqlName] = new Reference($serviceId);
                        } else {
                            $methods = $interface->getMethods(ReflectionMethod::IS_PUBLIC);

                            foreach ($methods as $method) {
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
                        }
                    }
                    $schemaBuilder->setArgument('$resolvers', $resolvers);

                    if ($debug) {
                        $debugResolvers = $container->getDefinition(Command\DebugResolversCommand::class);
                        $debugResolvers->setArgument('$resolversInfo', $resolversDebugInfo);
                    }
                }
            }
        );
    }
}
