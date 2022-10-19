<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use Closure;
use GraphQL\Error\Error;
use GraphQL\Error\SyntaxError;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\EnumTypeDefinitionNode;
use GraphQL\Language\AST\InputObjectTypeDefinitionNode;
use GraphQL\Language\AST\InterfaceTypeDefinitionNode;
use GraphQL\Language\AST\NodeList;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use GraphQL\Language\AST\ScalarTypeDefinitionNode;
use GraphQL\Language\AST\TypeDefinitionNode;
use GraphQL\Language\AST\TypeExtensionNode;
use GraphQL\Language\AST\UnionTypeDefinitionNode;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQL\Utils\BuildSchema;
use GraphQL\Utils\SchemaExtender;
use LogicException;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function assert;
use function count;
use function sprintf;

/**
 * https://github.com/webonyx/graphql-php/issues/500
 */
final class SchemaBuilder
{
    public function __construct(
        private readonly DocumentNodeProviderInterface $documentNodeProvider,
        private readonly bool $debug
    ) {
    }

    public function makeSchema(?Closure $typeConfigDecorator = null): Schema
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
            new DocumentNode(['definitions' => NodeList::create($nonExtendDefs)]),
            $typeConfigDecorator,
            $options
        );

        return SchemaExtender::extend(
            $schema,
            new DocumentNode(['definitions' => NodeList::create($extendDefs)]),
            $options,
            $typeConfigDecorator
        );
    }

    /**
     * @param array<string, array<string, callable>> $resolvers
     * @throws Error
     * @throws SyntaxError
     */
    public function makeExecutableSchema(
        array $resolvers,
        array $argumentsMapping,
        array $inputObjectsMapping,
        array $enumsMapping,
        Security $security,
        ValidatorInterface $validator
    ): Schema {
        $resolver = static function (mixed $objectValue, mixed $args, mixed $contextValue, ResolveInfo $info) use (
            $argumentsMapping,
            $resolvers,
            $validator,
            $security
        ): mixed {
            $isGrantedDirective = DirectiveHelper::getDirectiveValues('isGranted', $info);

            if ($isGrantedDirective && !$security->isGranted($isGrantedDirective['role'])) {
                throw new AuthorizationError($isGrantedDirective['role']);
            }

            $class = $argumentsMapping[$info->parentType->name][$info->fieldName] ?? null;
            if ($class) {
                $args = new $class(...$args);
                $errors = $validator->validate($args);
                if (count($errors) > 0) {
                    throw new ConstraintViolationException($errors);
                }
            }
            $objectResolver = $resolvers[$info->parentType->name][$info->fieldName] ?? throw new LogicException(
                    sprintf('Could not resolve %s.%s', $info->parentType->name, $info->fieldName)
                );

            return $objectResolver($objectValue, $args, $contextValue, $info);
        };

        $typeConfigDecorator = static function (
            array $typeConfig,
            TypeDefinitionNode $typeDefinitionNode,
            array $definitionMap
        ) use ($resolvers, $resolver, $enumsMapping, $inputObjectsMapping): array {
            $name = $typeConfig['name'];
            $typeResolvers = $resolvers[$name] ?? null;

            switch ($typeDefinitionNode::class) {
                case UnionTypeDefinitionNode::class:
                case InterfaceTypeDefinitionNode::class:
                    assert($typeResolvers !== null, sprintf('Missing resolvers for union/interface %s', $name));

                    $resolveType = [$typeResolvers, 'resolveType'];

                    $typeConfig['resolveType'] = static function (
                        mixed $objectValue,
                        mixed $context,
                        ResolveInfo $info
                    ) use (
                        $resolveType,
                        $definitionMap
                    ): Type|null {
                        $rawType = $resolveType($objectValue, $context, $info);

                        if (!$rawType) {
                            return null;
                        }

                        return $info->schema->getType($rawType);
                    };
                    break;
                case ObjectTypeDefinitionNode::class:
                    assert($typeResolvers !== null, sprintf('Missing resolvers for %s', $name));

                    $typeConfig['resolveField'] = $resolver;
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
                        $value['value'] = $enum::from($key);
                    }
                    break;
                case InputObjectTypeDefinitionNode::class:
                    $class = $inputObjectsMapping[$typeDefinitionNode->name->value] ?? null;
                    assert($class !== null, sprintf('Missing input %s', $name));

                    if ($class) {
                        $typeConfig['parseValue'] = static fn (array $values): object => new $class(...$values);
                    }
                    break;
            }

            return $typeConfig;
        };

        return $this->makeSchema($typeConfigDecorator);
    }
}
