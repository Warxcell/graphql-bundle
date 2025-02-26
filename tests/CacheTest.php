<?php

namespace Arxy\GraphQL\Tests;

use Arxy\GraphQL\Cache\CacheConfig;
use Arxy\GraphQL\Controller\Executor;
use Arxy\GraphQL\DocumentNodeProviderInterface;
use Arxy\GraphQL\ErrorsHandler;
use Arxy\GraphQL\GraphQL\ResolveInfo;
use Arxy\GraphQL\MiddlewareStack;
use Arxy\GraphQL\QueryContainerFactory;
use Arxy\GraphQL\QueryError;
use Arxy\GraphQL\SchemaBuilder;
use GraphQL\Executor\Promise\Adapter\SyncPromiseAdapter;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\Parser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CacheTest extends TestCase
{
    public function testCache(): void
    {
        $documentNodeProvider = new class implements DocumentNodeProviderInterface {
            public function getDocumentNode(): DocumentNode
            {
                return Parser::parse(
                /** @lang GraphQL */ '
type Query {
    objects1: [Object1!]!
}

type Object1 {
    id: ID!
    subObjects2(args: String): [Object2!]!
}

type Object2 {
    id: ID!
    fieldWithArgs(arg1: String): String!
}'
                );
            }

        };
        $schemaBuilder = new SchemaBuilder($documentNodeProvider, true);

        $resolversCalled = [];

        $counterMiddleware = function ($parent, $args, $context, ResolveInfo $info, \Closure $next) use (
            &$resolversCalled
        ) {
            $resolversCalled[$info->parentType->name][$info->fieldName] ??= 0;
            $resolversCalled[$info->parentType->name][$info->fieldName]++;

            return $next($parent, $args, $context, $info);
        };

        $schema = $schemaBuilder->makeExecutableSchema(
            resolvers: [
                'Query' => [
                    'objects1' => MiddlewareStack::wrap($counterMiddleware, function () {
                        $first = new \stdClass();
                        $first->id = 1;

                        $second = new \stdClass();
                        $second->id = 2;

                        return [$first, $second];
                    },),
                ],
                'Object1' => [
                    'id' => MiddlewareStack::wrap($counterMiddleware, function (\stdClass $parent) {
                        return $parent->id;
                    }),
                    'subObjects2' => MiddlewareStack::wrap($counterMiddleware, function (\stdClass $parent) {
                        $first = new \stdClass();
                        $first->id = sprintf('%s-%s', $parent->id, 3);

                        $second = new \stdClass();
                        $second->id = sprintf('%s-%s', $parent->id, 4);

                        return [$first, $second];
                    }),
                ],
                'Object2' => [
                    'id' => MiddlewareStack::wrap($counterMiddleware, function (\stdClass $parent) {
                        return $parent->id;
                    }),
                    'fieldWithArgs' => MiddlewareStack::wrap($counterMiddleware, function (\stdClass $parent, $args) {
                        return sprintf('%s-%s', $parent->id, $args['arg1']);
                    }),
                ],
            ],
            cacheResolvers: [
                'Object1' => [
                    'subObjects2' => function (\stdClass $parent) {
                        return new CacheConfig(
                            sprintf('object-%s', $parent->id),
                        );
                    },
                ],
            ],
            inputObjectsMapping: [],
            enumsMapping: [],
            validator: $this->createMock(ValidatorInterface::class),
            argumentsMapping: [],
        );

        $promiseAdaptor = new SyncPromiseAdapter();
        $errorsHandler = new ErrorsHandler($this->createMock(LoggerInterface::class));

        $cache = new ArrayAdapter();
        $executor = new Executor($schema, $promiseAdaptor, $errorsHandler, true, $cache);

        $queryContainerFactory = new QueryContainerFactory($schema, new ArrayAdapter());

        $query = /** @lang GraphQL */
            '
query ($arg1: String){
    objects1 {
        id
        subObjects2 {
            id
            fieldWithArgs(arg1: $arg1)
        }
    }
}';
        $queryContainer = $queryContainerFactory->create(
            query: $query,
            operationName: null,
            variables: [
                'arg1' => 'ARG1',
            ]
        );

        $result = $executor->execute($queryContainer, null);
        foreach ($result->errors as $error) {
            throw $error;
        }

        $expected = [
            'objects1' => [
                [
                    'id' => '1',
                    'subObjects2' => [
                        [
                            'id' => '1-3',
                            'fieldWithArgs' => '1-3-ARG1',
                        ],
                        [
                            'id' => '1-4',
                            'fieldWithArgs' => '1-4-ARG1',
                        ],
                    ],
                ],
                [
                    'id' => '2',
                    'subObjects2' => [
                        [
                            'id' => '2-3',
                            'fieldWithArgs' => '2-3-ARG1',
                        ],
                        [
                            'id' => '2-4',
                            'fieldWithArgs' => '2-4-ARG1',
                        ],
                    ],
                ],
            ],
        ];
        self::assertEquals($expected, $result->data);

        self::assertEquals([
            'Query' => [
                'objects1' => 1,
            ],
            'Object1' => [
                'id' => 2,
                'subObjects2' => 2,
            ],
            'Object2' => [
                'id' => 4,
                'fieldWithArgs' => 4,
            ],
        ], $resolversCalled);

        $result = $executor->execute($queryContainer, null);
        foreach ($result->errors as $error) {
            throw $error;
        }

        self::assertEquals($expected, $result->data);

        self::assertEquals([
            'Query' => [
                'objects1' => 2,
            ],
            'Object1' => [
                'id' => 4,
                'subObjects2' => 2,
            ],
            'Object2' => [
                'id' => 4,
                'fieldWithArgs' => 4,
            ],
        ], $resolversCalled);


        // Test if changing arg in sub-graph invalidates query
        $queryContainer = $queryContainerFactory->create(
            query: $query,
            operationName: null,
            variables: [
                'arg1' => null,
            ]
        );
        $result = $executor->execute($queryContainer, null);
        foreach ($result->errors as $error) {
            throw $error;
        }

        self::assertEquals([
            'Query' => [
                'objects1' => 3,
            ],
            'Object1' => [
                'id' => 6,
                'subObjects2' => 4,
            ],
            'Object2' => [
                'id' => 8,
                'fieldWithArgs' => 8,
            ],
        ], $resolversCalled);

        // Test change field selection in sub-graph invalidates cache
        $queryContainer = $queryContainerFactory->create(
        /** @lang GraphQL */ '
{
    objects1 {
        id
        subObjects2 {
            id
        }
    }
}',
            operationName: null,
            variables: []
        );
        $result = $executor->execute($queryContainer, null);
        foreach ($result->errors as $error) {
            throw $error;
        }

        self::assertEquals([
            'Query' => [
                'objects1' => 4,
            ],
            'Object1' => [
                'id' => 8,
                'subObjects2' => 6,
            ],
            'Object2' => [
                'id' => 12,
                'fieldWithArgs' => 8,
            ],
        ], $resolversCalled);
    }
}
