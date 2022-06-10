<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use Closure;
use GraphQL\Error\Error;
use GraphQL\Error\SyntaxError;
use GraphQL\Executor\Executor;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\EnumTypeDefinitionNode;
use GraphQL\Language\AST\InterfaceTypeDefinitionNode;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use GraphQL\Language\AST\ScalarTypeDefinitionNode;
use GraphQL\Language\AST\TypeDefinitionNode;
use GraphQL\Language\AST\TypeExtensionNode;
use GraphQL\Language\AST\UnionTypeDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Language\Printer;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;
use GraphQL\Utils\BuildSchema;
use GraphQL\Utils\SchemaExtender;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

use function assert;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function iterator_to_array;
use function mkdir;
use function sprintf;
use function var_export;

use const PHP_EOL;

/**
 * https://github.com/webonyx/graphql-php/issues/500
 */
final class SchemaBuilder
{
    /**
     * @param iterable<Module> $modules
     */
    public function __construct(
        private readonly iterable $modules,
        private readonly string $cacheDir,
        private readonly bool $debug
    ) {
    }

    /**
     * @throws SyntaxError
     * @throws Error
     */
    public function makeSchema(?Closure $typeConfigDecorator = null): Schema
    {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir);
        }

        $cacheFile = $this->cacheDir . '/schema.php';

        if ($this->debug || !file_exists($cacheFile)) {
            $schemaContent = file_get_contents(__DIR__ . '/Resources/graphql/schema.graphql');

            foreach ($this->modules as $module) {
                $schemaContent .= $module::getSchema() . PHP_EOL;
            }

            $document = Parser::parse($schemaContent);
            file_put_contents(
                $cacheFile,
                "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export(AST::toArray($document), true) . ";\n"
            );

            $finalSchema = Printer::doPrint($document);
            file_put_contents($this->cacheDir . '/schema.graphql', $finalSchema);
        } else {
            $document = AST::fromArray(require $cacheFile);
        }

        $nonExtendDefs = [];
        $extendDefs = [];
        foreach ($document->definitions as $definition) {
            if ($definition instanceof TypeExtensionNode) {
                $extendDefs[] = $definition;
            } else {
                $nonExtendDefs[] = $definition;
            }
        }

        $options = [
            'assumeValid' => $this->debug,
            'assumeValidSDL' => $this->debug,
        ];
        $schema = BuildSchema::build(
            new DocumentNode(['definitions' => $nonExtendDefs]),
            $typeConfigDecorator,
            $options
        );

        return SchemaExtender::extend(
            $schema,
            new DocumentNode(['definitions' => $extendDefs]),
            $options,
            $typeConfigDecorator
        );
    }

    /**
     * @param array<string, array<string, object>> $resolvers
     * @param iterable<Plugin> $plugins
     * @throws Error
     * @throws SyntaxError
     */
    public function makeExecutableSchema(
        array $resolvers,
        array $argumentsMapping,
        array $enums,
        DenormalizerInterface $serializer,
        iterable $plugins,
    ): Schema {
        $resolver = static function (mixed $objectValue, mixed $args, mixed $contextValue, ResolveInfo $info) use (
            $resolvers
        ) {
            if (isset($resolvers[$info->parentType->name][$info->fieldName])) {
                $objectResolver = $resolvers[$info->parentType->name][$info->fieldName];

                return call_user_func_array(
                    [$objectResolver, $info->fieldName],
                    [
                        $objectValue,
                        $args,
                        $contextValue,
                        $info,
                    ]
                );
            } else {
                return Executor::defaultFieldResolver($objectValue, $args, $contextValue, $info);
            }
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

        $resolver = static function (mixed $objectValue, mixed $args, mixed $contextValue, ResolveInfo $info) use (
            $resolver,
            $argumentsMapping,
            $serializer
        ) {
            if (isset($argumentsMapping[$info->parentType->name][$info->fieldName])) {
                $args = $serializer->denormalize($args, $argumentsMapping[$info->parentType->name][$info->fieldName]);
            }

            return $resolver($objectValue, $args, $contextValue, $info);
        };

        $typeConfigDecorator = static function (
            array $typeConfig,
            TypeDefinitionNode $typeDefinitionNode,
            array $definitionMap
        ) use ($resolvers, $resolver, $enums) {
            $name = $typeConfig['name'];
            $typeResolvers = $resolvers[$name] ?? null;

            if ($typeDefinitionNode instanceof UnionTypeDefinitionNode || $typeDefinitionNode instanceof InterfaceTypeDefinitionNode) {
                assert($typeResolvers instanceof UnionInterfaceResolver, sprintf('Missing resolvers for union/interface %s', $name));

                $resolveType = [$typeResolvers, 'resolveType'];

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
            } elseif ($typeDefinitionNode instanceof ScalarTypeDefinitionNode) {
                assert($typeResolvers instanceof ScalarResolver, sprintf('Missing resolvers for scalar %s', $name));
                $typeConfig['serialize'] = [$typeResolvers, 'serialize'];
                $typeConfig['parseValue'] = [$typeResolvers, 'parseValue'];
                $typeConfig['parseLiteral'] = [$typeResolvers, 'parseLiteral'];
            } elseif ($typeDefinitionNode instanceof ObjectTypeDefinitionNode) {
                $typeConfig['resolveField'] = $resolver;
            } elseif ($typeDefinitionNode instanceof EnumTypeDefinitionNode) {
                $enum = $enums[$typeDefinitionNode->name->value] ?? null;
                if ($enum) {
                    foreach ($typeConfig['values'] as $key => &$value) {
                        $value['value'] = $enum::from($key);
                    }
                }
            }

            return $typeConfig;
        };

        return $this->makeSchema($typeConfigDecorator);
    }
}
