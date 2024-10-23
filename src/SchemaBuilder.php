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

use Symfony\Component\Validator\Validator\ValidatorInterface;

use function assert;
use function count;
use function is_callable;
use function sprintf;

/**
 * https://github.com/webonyx/graphql-php/issues/500
 * @phpstan-import-type TypeConfigDecorator from ASTDefinitionBuilder
 * @phpstan-import-type FieldConfigDecorator from ASTDefinitionBuilder
 */
final class SchemaBuilder
{
    public function __construct(
        private readonly DocumentNodeProviderInterface $documentNodeProvider,
        private readonly bool $debug,
    ) {
    }

    /**
     * @param TypeConfigDecorator|null $typeConfigDecorator
     * @param FieldConfigDecorator|null $fieldConfigDecorator
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
     * @param array<string, array<string, class-string>> $argumentsMapping
     * @throws Error
     * @throws SyntaxError
     */
    public function makeExecutableSchema(
        array $resolvers,
        array $inputObjectsMapping,
        array $enumsMapping,
        ValidatorInterface $validator,
        array $argumentsMapping,
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
                        if (isset($resolvers[$name])) {
                            $resolver = [$resolvers[$name], 'resolve'];
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

        $fieldConfigDecorator = function (
            array $typeConfig,
            FieldDefinitionNode $fieldDefinitionNode,
            ObjectTypeDefinitionNode|ObjectTypeExtensionNode|InterfaceTypeDefinitionNode|InterfaceTypeExtensionNode $node
        ) use (
            $resolvers,
            $argumentsMapping,
            $validator
        ) {
            if (!$node instanceof ObjectTypeDefinitionNode && !$node instanceof ObjectTypeExtensionNode) {
                return $typeConfig;
            }

            $resolver = $resolvers[$node->name->value][$fieldDefinitionNode->name->value];
            $typeConfig['resolve'] = $resolver;


            $class = $argumentsMapping[$node->name->value][$fieldDefinitionNode->name->value];
            $typeConfig['argsMapper'] = function (array $args) use ($class, $validator) {
                $validate = count($args) > 0;

                $args = new $class(...$args);

                if ($validate) { // validate is slow - we are stopping it here, because its empty object anyway - we optimized it :)
                    $errors = $validator->validate($args);
                    if (count($errors) > 0) {
                        throw new ConstraintViolationException($errors);
                    }
                }

                return $args;
            };

            return $typeConfig;
        };

        return $this->makeSchema($typeConfigDecorator, $fieldConfigDecorator);
    }
}
