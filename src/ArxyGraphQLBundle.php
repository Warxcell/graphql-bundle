<?php

declare(strict_types=1);
/*
 * Copyright (C) 2016-2024 Taylor & Hart Limited
 * All Rights Reserved.
 *
 * NOTICE: All information contained herein is, and remains the property
 * of Taylor & Hart Limited and its suppliers, if any.
 *
 * All   intellectual   and  technical  concepts  contained  herein  are
 * proprietary  to  Taylor & Hart Limited  and  its suppliers and may be
 * covered  by  U.K.  and  foreign  patents, patents in process, and are
 * protected in full by copyright law. Dissemination of this information
 * or  reproduction  of this material is strictly forbidden unless prior
 * written permission is obtained from Taylor & Hart Limited.
 *
 * ANY  REPRODUCTION, MODIFICATION, DISTRIBUTION, PUBLIC PERFORMANCE, OR
 * PUBLIC  DISPLAY  OF  OR  THROUGH  USE OF THIS SOURCE CODE WITHOUT THE
 * EXPRESS  WRITTEN CONSENT OF RARE PINK LIMITED IS STRICTLY PROHIBITED,
 * AND  IN  VIOLATION  OF  APPLICABLE LAWS. THE RECEIPT OR POSSESSION OF
 * THIS  SOURCE CODE AND/OR RELATED INFORMATION DOES NOT CONVEY OR IMPLY
 * ANY  RIGHTS  TO REPRODUCE, DISCLOSE OR DISTRIBUTE ITS CONTENTS, OR TO
 * MANUFACTURE,  USE, OR SELL ANYTHING THAT IT MAY DESCRIBE, IN WHOLE OR
 * IN PART.
 */

namespace Arxy\GraphQL;

use Arxy\GraphQL\Debug\TimingMiddleware;
use Arxy\GraphQL\Security\SecurityCompilerPass;
use Arxy\GraphQL\Security\SecurityMiddleware;
use Closure;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use LogicException;
use ReflectionClass;
use ReflectionEnum;
use ReflectionException;
use ReflectionMethod;
use SplObjectStorage;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Throwable;

use function array_reverse;
use function assert;
use function count;
use function implode;
use function in_array;
use function is_callable;
use function is_int;
use function sprintf;
use function str_replace;

final class ArxyGraphQLBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(
            new class() implements CompilerPassInterface {
                public function process(ContainerBuilder $container): void
                {
                    $schemaBuilder = $container->get(SchemaBuilder::class);
                    assert($schemaBuilder instanceof SchemaBuilder);
                    $schema = $schemaBuilder->makeSchema();

                    $debug = $container->getParameter('kernel.debug');

                    $executableSchemaBuilderDefinition = $container->getDefinition('arxy.graphql.executable_schema');
                    $enumsMapping = $executableSchemaBuilderDefinition->getArgument('$enumsMapping');
                    $resolvers = [];

                    $resolversDebugInfo = [];

                    $uncheckedEnums = $enumsMapping;

                    foreach ($container->findTaggedServiceIds('arxy.graphql.resolver') as $serviceId => $tags) {
                        $service = $container->getDefinition($serviceId);

                        $reflection = $container->getReflectionClass($service->getClass());

                        assert($reflection instanceof ReflectionClass);

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
                                case $type instanceof EnumType:
                                    if (isset($resolvers[$graphqlName])) {
                                        throw new LogicException(
                                            sprintf('Multiple resolvers exists for %s', $graphqlName)
                                        );
                                    }

                                    $definition = $container->getDefinition($serviceId);
                                    $class = $definition->getClass();

                                    if (null === $class) {
                                        throw new LogicException(sprintf('Resolver for %s is missing', $graphqlName));
                                    }
                                    $enumResolver = [$class, 'resolve'];
                                    assert(is_callable($enumResolver));

                                    $values = new SplObjectStorage();
                                    foreach ($type->getValues() as $value) {
                                        try {
                                            $values->offsetSet($enumResolver($value->name), $value);
                                        } catch (Throwable $throwable) {
                                            throw new LogicException(
                                                sprintf('Could not resolve enum %s.%s', $graphqlName, $value->name),
                                                0,
                                                $throwable
                                            );
                                        }
                                    }

                                    try {
                                        $enumRefl = new ReflectionEnum($enumsMapping[$graphqlName]);
                                    } catch (ReflectionException $reflectionException) {
                                        throw new LogicException(
                                            sprintf('%s mapped to non-enum', $graphqlName),
                                            0,
                                            $reflectionException
                                        );
                                    }

                                    if (!$enumRefl->isBacked()) {
                                        throw new LogicException(
                                            sprintf('%s mapped to non-backed enum', $graphqlName)
                                        );
                                    }

                                    foreach ($enumRefl->getCases() as $case) {
                                        if ($values->offsetExists($case->getValue())) {
                                            continue;
                                        }
                                        throw new LogicException(
                                            sprintf(
                                                '%s:%s not found in %s',
                                                $enumRefl->getName(),
                                                $case->name,
                                                $graphqlName
                                            ),
                                        );
                                    }

                                    $resolvers[$graphqlName] = $class;

                                    unset($uncheckedEnums[$graphqlName]);
                                    break;
                                case $type instanceof InputObjectType:
                                case $type instanceof ScalarType:
                                case $type instanceof UnionType:
                                case $type instanceof InterfaceType:
                                    if (isset($resolvers[$graphqlName])) {
                                        throw new LogicException(
                                            sprintf('Multiple resolvers exists for %s', $graphqlName)
                                        );
                                    }
                                    $resolvers[$graphqlName] = new Reference($serviceId);
                                    break;
                                case $type instanceof ObjectType:
                                    $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

                                    foreach ($methods as $method) {
                                        $field = $method->getName();

                                        if ($method->isConstructor() || !$type->hasField($field)) {
                                            continue;
                                        }

                                        $resolverInfo = [];

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
                                            $middlewareDefId = sprintf(
                                                'arxy.graphql.middleware.%s.%s.%s',
                                                $graphqlName,
                                                $field,
                                                $serviceId
                                            );
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
                                            &$resolver,
                                            &$actuallyWrapResolver,
                                            &$resolverInfo
                                        ): void {
                                            $resolverInfo[] = $middlewareId;
                                            $resolver = $actuallyWrapResolver($resolver, $middlewareId);
                                        };

                                        $middlewares = array_reverse(
                                            $container->getParameter('arxy.graphql.middlewares')
                                        );

                                        $fieldDefinition = $type->getField($field);

                                        foreach ($fieldDefinition->astNode->directives as $directive) {
                                            if ('isGranted' === $directive->name->value) {
                                                foreach ($directive->arguments as $argument) {
                                                    if ('role' === $argument->name->value) {
                                                        if (!$argument->value instanceof StringValueNode) {
                                                            throw new LogicException('Role argument not String');
                                                        }

                                                        $securityMiddlewareId = sprintf(
                                                            'security_middleware_%s_%s',
                                                            $graphqlName,
                                                            $field
                                                        );
                                                        $container->setDefinition(
                                                            $securityMiddlewareId,
                                                            new Definition(
                                                                SecurityMiddleware::class,
                                                                [
                                                                    '$role' => $argument->value->value,
                                                                ]
                                                            )
                                                        );

                                                        $middlewares[$graphqlName][$field][] = $securityMiddlewareId;

                                                        continue 2;
                                                    }
                                                }
                                            }
                                        }

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
                                            foreach (
                                                array_reverse(
                                                    $middlewareOrFields
                                                ) as $fieldOrInt => $middlewareOrField
                                            ) {
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
                        $types = Type::builtInTypes();
                        if (in_array($type, $types)) {
                            // standard types have built-in resolvers.
                            continue;
                        }

                        switch (true) {
                            case $type instanceof EnumType:
                                if (!isset($uncheckedEnums[$type->name])) {
                                    break;
                                }
                                $phpEnum = $uncheckedEnums[$type->name];

                                $values = new SplObjectStorage();

                                foreach ($type->getValues() as $value) {
                                    try {
                                        $values->offsetSet($phpEnum::from($value->name), $value);
                                    } catch (Throwable $throwable) {
                                        throw new LogicException(
                                            sprintf('Could not resolve enum %s.%s', $type->name, $value->name),
                                            0,
                                            $throwable
                                        );
                                    }
                                }

                                foreach ($phpEnum::cases() as $case) {
                                    if ($values->offsetExists($case)) {
                                        continue;
                                    }
                                    throw new LogicException(
                                        sprintf('%s:%s not found in %s', $phpEnum, $case->name, $type->name),
                                    );
                                }

                                break;
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
