<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Codegen;

use Arxy\GraphQL\Enum;
use Arxy\GraphQL\EnumResolver;
use Arxy\GraphQL\Module;
use Arxy\GraphQL\Resolver;
use Arxy\GraphQL\ScalarResolver;
use Arxy\GraphQL\UnionInterfaceResolver;
use Exception;
use GraphQL\Error\Error;
use GraphQL\Error\SyntaxError;
use GraphQL\Executor\Promise\Promise;
use GraphQL\Language\AST\DefinitionNode;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\EnumTypeDefinitionNode;
use GraphQL\Language\AST\EnumTypeExtensionNode;
use GraphQL\Language\AST\FieldDefinitionNode;
use GraphQL\Language\AST\InputObjectTypeDefinitionNode;
use GraphQL\Language\AST\InputObjectTypeExtensionNode;
use GraphQL\Language\AST\InputValueDefinitionNode;
use GraphQL\Language\AST\InterfaceTypeDefinitionNode;
use GraphQL\Language\AST\InterfaceTypeExtensionNode;
use GraphQL\Language\AST\ListTypeNode;
use GraphQL\Language\AST\NamedTypeNode;
use GraphQL\Language\AST\NameNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\NonNullTypeNode;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use GraphQL\Language\AST\ObjectTypeExtensionNode;
use GraphQL\Language\AST\ScalarTypeDefinitionNode;
use GraphQL\Language\AST\ScalarTypeExtensionNode;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Language\AST\TypeNode;
use GraphQL\Language\AST\UnionTypeDefinitionNode;
use GraphQL\Language\AST\UnionTypeExtensionNode;
use GraphQL\Language\Parser;
use GraphQL\Type\Definition\ResolveInfo;
use LogicException;
use Nette\PhpGenerator\ClassLike;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\EnumType;
use Nette\PhpGenerator\InterfaceType;
use Nette\PhpGenerator\Method;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PhpNamespace;

use function array_map;
use function array_merge;
use function file_put_contents;
use function get_class;
use function glob;
use function implode;
use function in_array;
use function is_dir;
use function is_file;
use function iterator_to_array;
use function mkdir;
use function property_exists;
use function sprintf;
use function ucfirst;
use function unlink;

class Generator
{
    /**
     * @var DocumentNode[]
     */
    private array $allDocuments;

    /**
     * @var array<string, string>
     */
    private array $allModulesTypeMapping;

    /**
     * @var array<class-string, array<string, ClassLike>>
     */
    private array $typeRegistry = [];

    /**
     * @throws SyntaxError
     */
    public function __construct(
        private readonly iterable $modules
    ) {
        $mappings = [];
        /** @var DocumentNode[] $allDocs */
        $allDocs = [];
        foreach ($modules as $module) {
            $mappings = array_merge($mappings, $module::getTypeMapping());
            $allDocs[] = Parser::parse($module::getSchema());
        }
        $this->allModulesTypeMapping = $mappings;
        $this->allDocuments = $allDocs;
    }

    /**
     * @param class-string<Module> $module
     */
    private function writeGeneratedType(string $module, ClassLike $type): void
    {
        $file = new PhpFile();
        $file->setStrictTypes();
        $file->addNamespace((new PhpNamespace($module::getCodegenNamespace()))->add($type));
        file_put_contents(sprintf('%s/%s.php', $module::getCodegenDirectory(), $type->getName()), $file);
    }

    /**
     * @param class-string<Module> $module
     */
    private function addGeneratedType(string $module, ClassLike $type): void
    {
        if (isset($this->typeRegistry[$module][$type->getName()])) {
            return;
        }
        $this->typeRegistry[$module][$type->getName()] = $type;

        $this->writeGeneratedType($module, $type);
    }

    /**
     * @param class-string<Module> $module
     * @throws Exception
     */
    private function generate(string $module, DefinitionNode $definitionNode): ?ClassLike
    {
        return match (get_class($definitionNode)) {
            ObjectTypeDefinitionNode::class, ObjectTypeExtensionNode::class => $this->generateObjectType(
                $module,
                $definitionNode
            ),
            InputObjectTypeDefinitionNode::class, InputObjectTypeExtensionNode::class => $this->generateInputObjectType(
                $module,
                $definitionNode
            ),
            EnumTypeDefinitionNode::class, EnumTypeExtensionNode::class => $this->generateEnumType(
                $module,
                $definitionNode
            ),
            ScalarTypeDefinitionNode::class, ScalarTypeExtensionNode::class => $this->generateScalarType(
                $module,
                $definitionNode
            ),
            InterfaceTypeDefinitionNode::class, InterfaceTypeExtensionNode::class => $this->generateInterfaceType(
                $module,
                $definitionNode
            ),
            UnionTypeDefinitionNode::class, UnionTypeExtensionNode::class => $this->generateUnionType(
                $module,
                $definitionNode
            ),
            default => throw new LogicException(sprintf('Definition %s not supported', get_class($definitionNode))),
        };
    }

    /**
     * @param class-string<Module> $module
     * @throws Exception
     */
    private function handleDefinition(string $module, DefinitionNode $definitionNode): ?string
    {
        $generated = $this->generate($module, $definitionNode);
        if (!$generated) {
            return null;
        }

        $this->addGeneratedType($module, $generated);

        return $module::getCodegenNamespace() . '\\' . $generated->getName();
    }

    /**
     * @param class-string<Module> $module
     * @throws SyntaxError
     * @throws Exception
     */
    private function handleModule(string $module, DocumentNode $document): void
    {
        foreach ($document->definitions as $definition) {
            $this->handleDefinition($module, $definition);
        }
    }

    /**
     * @throws SyntaxError
     */
    public function execute(): void
    {
        foreach ($this->modules as $module) {
            if (!is_dir($module::getCodegenDirectory())) {
                mkdir($module::getCodegenDirectory());
            } else {
                $files = glob(sprintf('%s/*', $module::getCodegenDirectory()));
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
        }

        foreach ($this->modules as $i => $module) {
            $this->handleModule($module, $this->allDocuments[$i]);
        }
    }

    private function isNativeType(string $type): bool
    {
        return in_array($type, ['string', 'int', 'float', 'bool']);
    }

    /**
     * @throws Exception
     */
    private function handleDefinitionByName(string $name): string
    {
        switch ($name) {
            case 'ID':
            case 'String':
                return 'string';
            case 'Int':
                return 'int';
            case 'Float':
                return 'float';
            case 'Boolean':
                return 'bool';
            default:
                $handleType = function () use ($name): ?string {
                    foreach ($this->modules as $i => $module) {
                        foreach ($this->allDocuments[$i]->definitions as $definition) {
                            if (property_exists(
                                    $definition,
                                    'name'
                                )
                                && $definition->name instanceof NameNode
                                && $definition->name->value === $name) {
                                if ($definition instanceof ScalarTypeDefinitionNode || $definition instanceof ScalarTypeExtensionNode) {
                                    throw new LogicException(sprintf('Please define type for %s', $name));
                                }

                                return $this->handleDefinition(
                                    $module,
                                    $definition
                                );
                            }
                        }
                    }

                    return null;
                };

                return $this->allModulesTypeMapping[$name] ?? $handleType() ?? throw new LogicException(
                        sprintf('Definition %s not found', $name)
                    );
        }
    }

    /**
     * @throws Exception
     */
    public function getPhpTypeFromGraphQLType(TypeNode $typeNode): string
    {
        return match (get_class($typeNode)) {
            ListTypeNode::class => 'iterable',
            NonNullTypeNode::class => $this->getPhpTypeFromGraphQLType($typeNode->type),
            NamedTypeNode::class => $this->handleDefinitionByName($typeNode->name->value),
            default => throw new LogicException(),
        };
    }

    /**
     * @param class-string<Module> $module
     * @throws Exception
     */
    public function generateObjectType(
        string $module,
        ObjectTypeDefinitionNode|ObjectTypeExtensionNode $definitionNode
    ): ?ClassLike {
        $class = null;
        $type = $module::getTypeMapping()[$definitionNode->name->value] ?? null;
        if (!$type) {
            $class = new ClassType($definitionNode->name->value);
            $class->setFinal();
            $method = $class->addMethod('__construct');

            foreach ($definitionNode->fields as $field) {
                $method->addPromotedParameter($field->name->value)
                    ->setReadOnly()
                    ->setType($this->getPhpTypeFromGraphQLType($field->type))
                    ->setNullable(get_class($field->type) !== NonNullTypeNode::class);
            }
            $this->addGeneratedType($module, $class);
        }

        $resolvers = $this->generateResolversForObject($module, $definitionNode);
        $this->writeGeneratedType($module, $resolvers);

        return $class;
    }

    /**
     * @param class-string<Module> $module
     * @throws Exception
     */
    private function generateResolversForObject(
        string $module,
        ObjectTypeDefinitionNode|ObjectTypeExtensionNode $definitionNode
    ): ClassLike {
        $type = new InterfaceType($definitionNode->name->value . 'Resolver');
        $type->addExtend(Resolver::class);

        foreach ($definitionNode->fields as $field) {
            try {
                $argType = $this->generateFieldArgs($module, $definitionNode, $field);
            } catch (Exception $exception) {
                throw new Exception(
                    sprintf(
                        'Error during generating field %s of %s',
                        $field->name->value,
                        $definitionNode->name->value
                    ), 0, $exception
                );
            }

            $method = $type->addMethod($field->name->value);
            $method->setPublic();
            $method->setAbstract();
            $method->addParameter('parent')->setType(
                $this->allModulesTypeMapping[$definitionNode->name->value] ?? 'mixed'
            );
            $method->addParameter('args')->setType($argType);
            $method->addParameter('context')->setType('mixed');
            $method->addParameter('info')->setType(ResolveInfo::class);

            $returnType = $this->getPhpTypeFromGraphQLType($field->type);

            $returnTypes = [$returnType, Promise::class];
            if (!$field->type instanceof NonNullTypeNode) {
                $returnTypes[] = 'null';
            }
            $method->setReturnType($this->generateUnion($returnTypes));

            $genericsTypes = $this->generateUnion($this->getGenericsType($field->type));
            $promise = $this->wrapInPromise($genericsTypes);
            $method->addComment(sprintf('@return %s', $this->generateUnion([
                $genericsTypes,
                $promise,
            ])));
        }

        return $type;
    }

    private function wrapInPromise(string $type): string
    {
        return sprintf('\%s<%s>', Promise::class, $type);
    }

    /**
     * @param string[] $types
     */
    private function generateUnion(array $types): string
    {
        return implode('|', $types);
    }

    private function fixTypeForGenerics(string $type): string
    {
        if (!$this->isNativeType($type)) {
            $type = '\\' . $type;
        }

        return $type;
    }

    /**
     * @return string[]
     * @throws Exception
     */
    private function getGenericsType(TypeNode $typeNode, ?TypeNode $parentType = null): array
    {
        return match (get_class($typeNode)) {
            ListTypeNode::class => [sprintf('iterable<%s>', $this->generateUnion($this->getGenericsType($typeNode->type, $typeNode)))],
            NonNullTypeNode::class => $this->getGenericsType($typeNode->type, $typeNode),
            NamedTypeNode::class => (function () use ($typeNode, $parentType) {
                $type = $this->fixTypeForGenerics($this->handleDefinitionByName($typeNode->name->value));
                if ($parentType === null) {
                    return [$type, 'null'];
                }

                return [$type];
            })(),
            default => throw new LogicException(),
        };
    }

    /**
     * @param class-string<Module> $module
     * @throws Exception
     */
    public function generateFieldArgs(
        string $module,
        ObjectTypeDefinitionNode|ObjectTypeExtensionNode $objectType,
        FieldDefinitionNode $definitionNode
    ): string {
        $class = new ClassType(
            sprintf('%s%sArgs', ucfirst($objectType->name->value), ucfirst($definitionNode->name->value))
        );
        $class->setFinal();
        $method = $class->addMethod('__construct');

        foreach ($definitionNode->arguments as $field) {
            $this->handleInputValue($method, $field);
        }

        $this->addGeneratedType($module, $class);

        return $module::getCodegenNamespace() . '\\' . $class->getName();
    }

    /**
     * @param class-string<Module> $module
     * @throws Exception
     */
    public function generateInputObjectType(
        string $module,
        InputObjectTypeDefinitionNode|InputObjectTypeExtensionNode $definitionNode
    ): ClassLike {
        $class = new ClassType($definitionNode->name->value);
        $class->setFinal();
        $method = $class->addMethod('__construct');

        foreach ($definitionNode->fields as $field) {
            $this->handleInputValue($method, $field);
        }

        $this->addGeneratedType($module, $class);

        return $class;
    }

    /**
     * @throws Exception
     */
    private function handleInputValue(Method $method, InputValueDefinitionNode $definitionNode): void
    {
        $nullable = get_class($definitionNode->type) !== NonNullTypeNode::class;
        ($nullable ? $method->addPromotedParameter($definitionNode->name->value, null) : $method->addPromotedParameter($definitionNode->name->value))
            ->setReadOnly()
            ->setType($this->getPhpTypeFromGraphQLType($definitionNode->type))
            ->setNullable($nullable);

        $method->addComment(sprintf('@param %s $%s', $this->generateUnion($this->getGenericsType($definitionNode->type)), $definitionNode->name->value));
    }

    /**
     * @param class-string<Module> $module
     */
    public function generateEnumType(
        string $module,
        EnumTypeDefinitionNode|EnumTypeExtensionNode $definitionNode
    ): ClassLike {
        $typeName = $module::getTypeMapping()[$definitionNode->name->value] ?? null;

        if (null !== $typeName) {
            $type = new InterfaceType($definitionNode->name->value);
            $type->addExtend(EnumResolver::class);

            foreach ($definitionNode->values as $value) {
                $type->addMethod($value->name->value)->setPublic()->setReturnType($typeName);
            }

            return $type;
        } else {
            $enum = new EnumType($definitionNode->name->value);
            $enum->addAttribute(Enum::class);
            foreach ($definitionNode->values as $value) {
                $enum->addCase($value->name->value, $value->name->value);
            }

            return $enum;
        }
    }

    /**
     * @param class-string<Module> $module
     */
    public function generateScalarType(
        string $module,
        ScalarTypeDefinitionNode|ScalarTypeExtensionNode $definitionNode
    ): ClassLike {
        $type = new InterfaceType($definitionNode->name->value . 'Resolver');
        $type->addExtend(ScalarResolver::class);
        $serialize = $type->addMethod('serialize')->setReturnType('string');
        $serialize->setPublic();
        $serialize->addParameter('value')->setType($module::getTypeMapping()[$definitionNode->name->value] ?? 'mixed');
        $serialize->setBody('return $value;');

        $parseValue = $type->addMethod('parseValue')->setReturnType(
            $module::getTypeMapping()[$definitionNode->name->value] ?? 'mixed'
        );
        $parseValue->setPublic();
        $parseValue->addParameter('value')->setType('string');
        $parseValue->setBody('return $value;');

        $parseLiteral = $type->addMethod('parseLiteral')->setReturnType(
            $module::getTypeMapping()[$definitionNode->name->value] ?? 'mixed'
        );
        $parseLiteral->setPublic();
        $parseLiteral->addParameter('valueNode')->setType(Node::class);
        $parseLiteral->addParameter('variables', null)->setType('?array');

        $stringValueNode = StringValueNode::class;
        $error = Error::class;
        $parseLiteral->addComment(sprintf('@throws \%s', $error));
        $parseLiteral->setBody(
            <<<RETURN
           if (!\$valueNode instanceof \\$stringValueNode) {
            throw new \\$error('Query error: Can only parse strings got: ' . \$valueNode->kind, [\$valueNode]);
        }
        return \$valueNode->value;
        RETURN
        );

        return $type;
    }

    /**
     * @param class-string<Module> $module
     */
    private function generateUnionType(
        string $module,
        UnionTypeDefinitionNode|UnionTypeExtensionNode $definitionNode
    ): ClassLike {
        $type = new InterfaceType($definitionNode->name->value . 'Resolver');
        $type->addExtend(UnionInterfaceResolver::class);
        $resolveType = $type->addMethod('resolveType');
        $resolveType->setPublic();
        $resolveType->setReturnType('string');
        $resolveType->addParameter('value')->setType(
            implode(
                '|',
                array_map(
                /**
                 * @throws Exception
                 */
                    [$this, 'getPhpTypeFromGraphQLType'],
                    iterator_to_array($definitionNode->types)
                )
            )
        );
        $resolveType->addParameter('context')->setType('mixed');
        $resolveType->addParameter('info')->setType(ResolveInfo::class);

        return $type;
    }

    /**
     * @param class-string<Module> $module
     */
    private function generateInterfaceType(
        string $module,
        InterfaceTypeDefinitionNode|InterfaceTypeExtensionNode $definitionNode
    ): ClassLike {
        /** @var NamedTypeNode[] $allTypesThatImplements */
        $allTypesThatImplements = [];

        foreach ($this->allDocuments as $document) {
            foreach ($document->definitions as $definition) {
                if (!$definition instanceof ObjectTypeDefinitionNode && !$definition instanceof ObjectTypeExtensionNode) {
                    continue;
                }

                foreach ($definition->interfaces as $interface) {
                    if ($interface->name->value === $definitionNode->name->value) {
                        $allTypesThatImplements[] = new NamedTypeNode([
                            'name' => $definition->name,
                        ]);
                    }
                }
            }
        }

        $type = new InterfaceType($definitionNode->name->value . 'Resolver');
        $type->addExtend(UnionInterfaceResolver::class);
        $resolveType = $type->addMethod('resolveType');
        $resolveType->setPublic();
        $resolveType->setReturnType('string');
        $resolveType->addParameter('value')->setType(
            implode(
                '|',
                array_map(
                /**
                 * @throws Exception
                 */
                    [$this, 'getPhpTypeFromGraphQLType'],
                    $allTypesThatImplements
                )
            )
        );
        $resolveType->addParameter('context')->setType('mixed');
        $resolveType->addParameter('info')->setType(ResolveInfo::class);

        return $type;
    }
}
