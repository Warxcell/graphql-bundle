<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use LogicException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionObject;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\Bundle;

use function assert;
use function count;
use function in_array;
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
                    $schemaBuilder = $container->getDefinition('arxy.graphql.executable_schema');
                    $resolvers = [];
                    $argumentsMapping = [];
                    $enums = [];

                    // TODO: Better way
                    $reflection = new ReflectionObject($container);
                    $classReflectors = $reflection->getProperty('classReflectors');
                    $classReflectors->setAccessible(true);
                    $reflectors = $classReflectors->getValue($container);

                    foreach ($reflectors as $reflector) {
                        if (!$reflector) {
                            continue;
                        }
                        assert($reflector instanceof ReflectionClass);
                        if (in_array($reflector->getName(), [Query::class, Mutation::class])) {
                            continue;
                        }

                        if (count($reflector->getAttributes(Enum::class)) > 0) {
                            $enums[$reflector->getShortName()] = $reflector->getName();
                        }
                    }

                    foreach ($container->findTaggedServiceIds('arxy.graphql.resolver') as $serviceId => $tags) {
                        $service = $container->getDefinition($serviceId);

                        $reflection = new ReflectionClass($service->getClass());

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

                        $graphqlName = str_replace('Resolver', '', $interface->getShortName());

                        if ($reflection->implementsInterface(ScalarResolverInterface::class)) {
                            $resolvers[$graphqlName] = new Reference($serviceId);
                        } elseif ($reflection->implementsInterface(
                                UnionResolverInterface::class
                            )
                            || $reflection->implementsInterface(InterfaceResolverInterface::class)) {
                            $resolvers[$graphqlName] = new Reference($serviceId);
                        } else {
                            $methods = $interface->getMethods(ReflectionMethod::IS_PUBLIC);

                            foreach ($methods as $method) {
                                $field = $method->getName();
                                $params = $method->getParameters();

                                assert(isset($params[1]), 'Missing args parameter');

                                if (isset($resolvers[$graphqlName][$field])) {
                                    throw new LogicException(
                                        sprintf('Multiple resolvers exists for %s.%s', $graphqlName, $field)
                                    );
                                }

                                $argumentsMapping[$graphqlName][$field] = $params[1]->getType()->getName();
                                $resolvers[$graphqlName][$field] = new Reference($serviceId);
                            }
                        }
                    }
                    $schemaBuilder->setArgument('$argumentsMapping', $argumentsMapping);
                    $schemaBuilder->setArgument('$resolvers', $resolvers);
                    $schemaBuilder->setArgument('$enums', $enums);
                }
            }
        );
    }
}
