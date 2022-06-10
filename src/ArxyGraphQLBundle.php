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

use function count;
use function sprintf;
use function str_replace;

final class ArxyGraphQLBundle extends Bundle
{
    public function build(ContainerBuilder $container)
    {
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container)
            {
                $objectTypes = [];

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
                    if (count($reflector->getAttributes(Enum::class)) > 0) {
                        $enums[$reflector->getShortName()] = $reflector->getName();
                    }

                    //$implements = $reflector->getInterfaces();
                    //foreach ($implements as $interface) {
                    //    if ($interface->implementsInterface(Resolver::class)) {
                    //        $graphqlName = str_replace('Resolver', '', $interface->getShortName());
                    //
                    //        $objectTypes[$graphqlName] = [];
                    //
                    //        foreach ($interface->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    //            $objectTypes[$graphqlName][] = $method->getName();
                    //        }
                    //        break;
                    //    }
                    //}
                }

                $schemaBuilder = $container->getDefinition('arxy.graphql.executable_schema');

                $resolvers = [];
                $argumentsMapping = [];
                foreach ($container->findTaggedServiceIds('arxy.graphql.resolver') as $serviceId => $tags) {
                    $service = $container->getDefinition($serviceId);

                    $refl = new ReflectionClass($service->getClass());

                    $implements = $refl->getInterfaces();

                    $interface = null;
                    foreach ($implements as $interface) {
                        if ($interface->implementsInterface(Resolver::class)) {
                            break;
                        }
                    }
                    if (!$interface) {
                        throw new LogicException(sprintf('Could not determine graphql object name for %s', $refl->getName()));
                    }

                    $graphqlName = str_replace('Resolver', '', $interface->getShortName());

                    if ($refl->implementsInterface(ScalarResolver::class)) {
                        $resolvers[$graphqlName] = new Reference($serviceId);
                    } elseif ($refl->implementsInterface(UnionInterfaceResolver::class)) {
                        $resolvers[$graphqlName] = new Reference($serviceId);
                    } else {
                        $methods = $interface->getMethods(ReflectionMethod::IS_PUBLIC);

                        foreach ($methods as $method) {
                            $field = $method->getName();
                            $params = $method->getParameters();

                            if (isset($params[1])) {
                                $argumentsMapping[$graphqlName][$field] = $params[1]->getType()->getName();
                            }
                            $resolvers[$graphqlName][$field] = new Reference($serviceId);
                        }
                    }
                }

                //$objectWithNoResolvers = [];
                //$objectWithMissingFields = [];
                //foreach ($objectTypes as $objectType => $fields) {
                //    if (!isset($resolvers[$objectType])) {
                //        $objectWithNoResolvers[] = $objectType;
                //    } else {
                //        foreach ($fields as $field) {
                //            if (!$resolvers[$objectType] instanceof Reference && !isset($resolvers[$objectType][$field])) {
                //                $objectWithMissingFields[$objectType][] = $field;
                //            }
                //        }
                //    }
                //}
                //
                //if (count($objectWithNoResolvers) > 0 || count($objectWithMissingFields) > 0) {
                //    $messages = sprintf('Objects with no resolvers %s', implode(', ', $objectWithNoResolvers));
                //    throw new LogicException($messages);
                //}

                $schemaBuilder->setArgument('$argumentsMapping', $argumentsMapping);
                $schemaBuilder->setArgument('$resolvers', $resolvers);
                $schemaBuilder->setArgument('$enums', $enums);
            }
        });
    }
}
