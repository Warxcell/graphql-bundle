<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use BackedEnum;
use GraphQL\Error\Error;
use GraphQL\Error\SyntaxError;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\EnumTypeDefinitionNode;
use GraphQL\Language\AST\FieldDefinitionNode;
use GraphQL\Language\AST\InputObjectTypeDefinitionNode;
use GraphQL\Language\AST\InterfaceTypeDefinitionNode;
use GraphQL\Language\AST\InterfaceTypeExtensionNode;
use GraphQL\Language\AST\NodeList;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use GraphQL\Language\AST\ObjectTypeExtensionNode;
use GraphQL\Language\AST\ScalarTypeDefinitionNode;
use GraphQL\Language\AST\TypeDefinitionNode;
use GraphQL\Language\AST\TypeExtensionNode;
use GraphQL\Language\AST\UnionTypeDefinitionNode;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQL\Utils\ASTDefinitionBuilder;
use GraphQL\Utils\BuildSchema;
use GraphQL\Utils\SchemaExtender;
use LogicException;

use Symfony\Component\Validator\Validator\ValidatorInterface;

use function assert;
use function is_callable;
use function sprintf;

/**
 * https://github.com/webonyx/graphql-php/issues/500
 * @phpstan-import-type TypeConfigDecorator from ASTDefinitionBuilder
 */
final class SchemaBuilder
{
    public function __construct(
        private readonly DocumentNodeProviderInterface $documentNodeProvider,
        private readonly bool $debug
    ) {
    }

    /**
     * @param TypeConfigDecorator|null $typeConfigDecorator
     */
    public function makeSchema(?callable $typeConfigDecorator = null, ?callable $fieldConfigDecorator = null): Schema
    {
        $document = $this->documentNodeProvider->getDocumentNode();

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
            'assumeValid' => !$this->debug,
            'assumeValidSDL' => !$this->debug,
        ];
        $schema = BuildSchema::build(
            new DocumentNode(['definitions' => new NodeList($nonExtendDefs)]),
            $typeConfigDecorator,
            $options,
            $fieldConfigDecorator
        );

        return SchemaExtender::extend(
            $schema,
            new DocumentNode(['definitions' => new NodeList($extendDefs)]),
            $options,
            $typeConfigDecorator,
            $fieldConfigDecorator
        );
    }

    /**
     * @param array<string, array<string, callable(): mixed>|object{resolve: callable(): mixed}|object{resolveType: callable(): string}> $resolvers
     * @param array<string, class-string> $inputObjectsMapping
     * @param array<string, class-string<BackedEnum>> $enumsMapping
     * @throws Error
     * @throws SyntaxError
     */
    public function makeExecutableSchema(
        array $resolvers,
        array $inputObjectsMapping,
        array $enumsMapping,
        array $argsMapping,
        ValidatorInterface $validator
    ): Schema {
        $typeConfigDecorator = static function (
            array $typeConfig,
            TypeDefinitionNode $typeDefinitionNode,
        ) use ($resolvers, $enumsMapping, $inputObjectsMapping): array {
            $name = $typeConfig['name'];
            $typeResolvers = $resolvers[$name] ?? null;

            switch ($typeDefinitionNode::class) {
                case UnionTypeDefinitionNode::class:
                case InterfaceTypeDefinitionNode::class:
                    assert($typeResolvers !== null, sprintf('Missing resolvers for union/interface %s', $name));
                    $resolveType = [$typeResolvers, 'resolveType'];
                    assert(is_callable($resolveType));

                    $typeConfig['resolveType'] = static function (
                        mixed $objectValue,
                        mixed $context,
                        ResolveInfo $info
                    ) use (
                        $resolveType,
                    ): Type|null {
                        $rawType = $resolveType($objectValue, $context, $info);

                        if (!$rawType) {
                            return null;
                        }

                        return $info->schema->getType($rawType);
                    };
                    break;
                case ObjectTypeDefinitionNode::class:
                    break;
                case ScalarTypeDefinitionNode::class:
                    assert($typeResolvers !== null, sprintf('Missing resolvers for scalar %s', $name));

                    $typeConfig['serialize'] = [$typeResolvers, 'serialize'];
                    $typeConfig['parseValue'] = [$typeResolvers, 'parseValue'];
                    $typeConfig['parseLiteral'] = [$typeResolvers, 'parseLiteral'];
                    break;
                case EnumTypeDefinitionNode::class:
                    $enum = $enumsMapping[$name] ?? null;
                    assert($enum !== null, sprintf('Missing enum %s', $name));

                    foreach ($typeConfig['values'] as $key => &$value) {
                        if ($typeResolvers) {
                            $resolver = [$typeResolvers, 'resolve'];
                            assert(is_callable($resolver));
                            $enumValue = $resolver($key);
                        } else {
                            $enumValue = $enum::from($key);
                        }
                        $value['value'] = $enumValue;
                    }
                    break;
                case InputObjectTypeDefinitionNode::class:
                    if ($typeResolvers) {
                        $resolverCallable = [$typeResolvers, 'resolve'];
                        assert(is_callable($resolverCallable));
                        $typeConfig['parseValue'] = static fn(array $values): mixed => $resolverCallable(...$values);
                    } else {
                        $class = $inputObjectsMapping[$typeDefinitionNode->name->value] ?? null;
                        assert($class !== null, sprintf('Missing input %s', $name));

                        $typeConfig['parseValue'] = static fn(array $values): object => new $class(...$values);
                    }
                    break;
            }

            return $typeConfig;
        };

        $fieldConfigDecorator = static function (
            array $config,
            FieldDefinitionNode $fieldDefinitionNode,
            ObjectTypeDefinitionNode|ObjectTypeExtensionNode|InterfaceTypeDefinitionNode|InterfaceTypeExtensionNode $node
        ) use ($resolvers, $argsMapping, $validator): array {
            if (!$node instanceof ObjectTypeDefinitionNode) {
                return $config;
            }

            $config['resolve'] = $resolvers[$node->name->value][$fieldDefinitionNode->name->value];

            $argsClass = $argsMapping[$node->name->value][$fieldDefinitionNode->name->value] ?? null;
            if ($argsClass) {
                $config['argsMapper'] = new ArgumentMapper($argsClass, $validator);
            }

            return $config;
        };

        return $this->makeSchema($typeConfigDecorator, $fieldConfigDecorator);
    }
}
