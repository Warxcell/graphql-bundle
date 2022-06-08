<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use Closure;
use GraphQL\Error\Error;
use GraphQL\Error\SyntaxError;
use GraphQL\Language\AST\EnumTypeDefinitionNode;
use GraphQL\Language\AST\InterfaceTypeDefinitionNode;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use GraphQL\Language\AST\ScalarTypeDefinitionNode;
use GraphQL\Language\AST\TypeDefinitionNode;
use GraphQL\Language\AST\UnionTypeDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Language\Printer;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;
use GraphQL\Utils\SchemaExtender;
use Symfony\Component\Finder\Finder;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

use function array_map;
use function assert;
use function enum_exists;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_array;
use function is_dir;
use function iterator_to_array;
use function mkdir;
use function var_export;

use const PHP_EOL;

/**
 * https://github.com/webonyx/graphql-php/issues/500
 */
final class SchemaBuilder
{
    public function __construct(
        private readonly array $schemas,
        private readonly string $cacheDir,
        private readonly bool $debug
    ) {
    }

    /**
     * @throws SyntaxError
     * @throws Error
     */
    public function makeSchema(?Closure $typeConfigDecorator = null, ?Closure $defaultResolver = null): Schema
    {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir);
        }

        $cacheFile = $this->cacheDir . '/schema.php';
        //$cacheFileMain = $this->cacheDir . '/main_schema.php';

        if ($this->debug || !file_exists($cacheFile)) {
            $schemaContent = implode(
                PHP_EOL,
                array_map(static function (string $fileOrDir): string {
                    $finder = new Finder();
                    $finder->files()->in($fileOrDir)->name('*.graphql');

                    $schema = '';
                    foreach ($finder as $file) {
                        $schema .= PHP_EOL . file_get_contents($file->getRealPath());
                    }

                    return $schema;
                }, $this->schemas)
            );
            $document = Parser::parse($schemaContent);
            file_put_contents(
                $cacheFile,
                "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export(AST::toArray($document), true) . ";\n"
            );

            //$main = Parser::parse(file_get_contents(__DIR__ . '/schema.graphql'));
            //file_put_contents($cacheFileMain, "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export(AST::toArray($main), true) . ";\n");

            //            $schema = '';
            //foreach ($this->graphqlDirectives as $graphqlDirective) {
            //    $schema .= PHP_EOL . Printer::doPrint($graphqlDirective);
            //}
            //foreach ($this->graphqlTypes as $graphqlType) {
            //    $schema .= PHP_EOL . Printer::doPrint($graphqlType);
            //}
            //file_put_contents($cacheFile, $schema);

            $finalSchema = Printer::doPrint($document);
            file_put_contents($this->cacheDir . '/schema.graphql', $finalSchema);
        } else {
            //$main = AST::fromArray(require $cacheFileMain);
            $document = AST::fromArray(require $cacheFile);
        }

        $options = [
            'assumeValid' => $this->debug,
            'assumeValidSDL' => $this->debug,
        ];
        //$schema = BuildSchema::build($document, null, $options);

        $schema = new Schema([
            'query' => new ObjectType([
                'name' => 'Query',
                'resolveField' => $defaultResolver,
            ]),
            'mutation' => new ObjectType([
                'name' => 'Mutation',
                'resolveField' => $defaultResolver,
            ]),
        ]);

        return SchemaExtender::extend($schema, $document, $options, $typeConfigDecorator);
    }

    /**
     * @param iterable<ResolverMapInterface> $resolverMaps
     * @param iterable<Plugin> $plugins
     * @throws Error
     * @throws SyntaxError
     */
    public function makeExecutableSchema(
        iterable $resolverMaps,
        iterable $plugins,
        PropertyAccessorInterface $propertyAccessor,
    ): Schema {
        $resolvers = [];
        foreach ($resolverMaps as $resolverMap) {
            foreach ($resolverMap->map() as $objectType => $fields) {
                if (is_array($fields)) {
                    $resolvers[$objectType] = ($resolvers[$objectType] ?? []) + $fields;
                } else {
                    $resolvers[$objectType] = $fields;
                }
            }
        }

        $resolver = static function ($objectValue, $args, $contextValue, ResolveInfo $info) use (
            $resolvers,
            $propertyAccessor
        ) {
            $value = null;
            if (isset($resolvers[$info->parentType->name][$info->fieldName])) {
                $value = $resolvers[$info->parentType->name][$info->fieldName](
                    $objectValue,
                    $args,
                    $contextValue,
                    $info
                );
            } elseif ($objectValue) {
                $fieldName = $info->fieldName;
                if (is_array($objectValue)) {
                    $fieldName = '[' . $fieldName . ']';
                }
                $value = $propertyAccessor->getValue($objectValue, $fieldName);
            }

            return $value instanceof Closure
                ? $value($objectValue, $args, $contextValue, $info)
                : $value;
        };

        $plugins = iterator_to_array($plugins);
        $resolveMiddleware = static function ($plugins, $offset) use (&$resolveMiddleware, $resolver): Closure {
            if (!isset($plugins[$offset])) {
                return $resolver;
            }
            $next = $resolveMiddleware($plugins, $offset + 1);

            $plugin = $plugins[$offset];

            assert($plugin instanceof Plugin);

            return $plugin->onResolverCalled($next);
        };
        $resolver = $resolveMiddleware($plugins, 0);

        $typeConfigDecorator = static function (
            array $typeConfig,
            TypeDefinitionNode $typeDefinitionNode,
            array $definitionMap
        ) use ($resolvers, $resolver) {
            $name = $typeConfig['name'];
            $typeResolvers = $resolvers[$name] ?? [];

            if ($typeDefinitionNode instanceof UnionTypeDefinitionNode || $typeDefinitionNode instanceof InterfaceTypeDefinitionNode) {
                $resolveType = $typeResolvers[ResolverMapInterface::RESOLVE_TYPE] ?? null;
                if ($resolveType) {
                    $typeConfig['resolveType'] = static function ($objectValue, $context, ResolveInfo $info) use (
                        $resolveType,
                        $definitionMap
                    ) {
                        $rawType = $resolveType($objectValue, $context, $info);

                        if (!$rawType) {
                            return null;
                        }

                        return $info->schema->getType($rawType);
                    };
                }
            } elseif ($typeDefinitionNode instanceof ScalarTypeDefinitionNode) {
                $typeConfig['serialize'] = $typeResolvers[ResolverMapInterface::SERIALIZE] ?? null;
                $typeConfig['parseValue'] = $typeResolvers[ResolverMapInterface::PARSE_VALUE] ?? null;
                $typeConfig['parseLiteral'] = $typeResolvers[ResolverMapInterface::PARSE_LITERAL] ?? null;
            } elseif ($typeDefinitionNode instanceof ObjectTypeDefinitionNode) {
                $typeConfig['resolveField'] = $resolver;
            } elseif ($typeDefinitionNode instanceof EnumTypeDefinitionNode) {
                if (is_array($typeResolvers)) {
                    foreach ($typeConfig['values'] as $key => &$value) {
                        $value['value'] = $typeResolvers[$key] ?? $key;
                    }
                } elseif (enum_exists($typeResolvers)) {
                    foreach ($typeConfig['values'] as $key => &$value) {
                        $value['value'] = $typeResolvers::from($key);
                    }
                }
            }

            return $typeConfig;
        };

        return $this->makeSchema($typeConfigDecorator, $resolver);
    }
}
