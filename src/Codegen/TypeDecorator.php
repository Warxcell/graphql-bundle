<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Codegen;

use Arxy\GraphQL\Enum;
use Arxy\GraphQL\InterfaceResolver;
use Arxy\GraphQL\InterfaceResolverInterface;
use Arxy\GraphQL\Resolver;
use Arxy\GraphQL\ResolverInterface;
use Arxy\GraphQL\ScalarResolver;
use Arxy\GraphQL\ScalarResolverInterface;
use Arxy\GraphQL\UnionResolver;
use Arxy\GraphQL\UnionResolverInterface;
use Arxy\GraphQLCodegen\ModuleInterface;
use Arxy\GraphQLCodegen\TypeDecoratorInterface;
use GraphQL\Language\AST\EnumTypeDefinitionNode;
use GraphQL\Language\AST\EnumTypeExtensionNode;
use GraphQL\Language\AST\FieldDefinitionNode;
use GraphQL\Language\AST\InputObjectTypeDefinitionNode;
use GraphQL\Language\AST\InputObjectTypeExtensionNode;
use GraphQL\Language\AST\InterfaceTypeDefinitionNode;
use GraphQL\Language\AST\InterfaceTypeExtensionNode;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use GraphQL\Language\AST\ObjectTypeExtensionNode;
use GraphQL\Language\AST\ScalarTypeDefinitionNode;
use GraphQL\Language\AST\ScalarTypeExtensionNode;
use GraphQL\Language\AST\UnionTypeDefinitionNode;
use GraphQL\Language\AST\UnionTypeExtensionNode;
use Nette\PhpGenerator\ClassLike;
use Nette\PhpGenerator\InterfaceType;

use function assert;

final class TypeDecorator implements TypeDecoratorInterface
{
    public function handleObject(
        ModuleInterface $module,
        ObjectTypeDefinitionNode|ObjectTypeExtensionNode $objectNode,
        ClassLike $classLike
    ): void {
    }

    public function handleObjectFieldArgs(
        ModuleInterface $module,
        ObjectTypeDefinitionNode|ObjectTypeExtensionNode $objectNode,
        FieldDefinitionNode $fieldNode,
        ClassLike $classLike
    ): void {
    }

    public function handleObjectResolver(
        ModuleInterface $module,
        ObjectTypeDefinitionNode|ObjectTypeExtensionNode $objectNode,
        ClassLike $classLike
    ): void {
        assert($classLike instanceof InterfaceType);
        $classLike->addExtend(ResolverInterface::class);
    }

    public function handleInputObjectType(
        ModuleInterface $module,
        InputObjectTypeExtensionNode|InputObjectTypeDefinitionNode $definitionNode,
        ClassLike $classLike
    ): void {
    }

    public function handleEnumType(
        ModuleInterface $module,
        EnumTypeDefinitionNode|EnumTypeExtensionNode $enumNode,
        ClassLike $classLike
    ): void {
        $classLike->addAttribute(Enum::class);
    }

    public function handleScalarResolver(
        ModuleInterface $module,
        ScalarTypeExtensionNode|ScalarTypeDefinitionNode $scalarNode,
        ClassLike $classLike
    ): void {
        assert($classLike instanceof InterfaceType);
        $classLike->addExtend(ScalarResolverInterface::class);
    }

    public function handleInterfaceResolver(
        ModuleInterface $module,
        InterfaceTypeDefinitionNode|InterfaceTypeExtensionNode $interfaceNode,
        ClassLike $classLike
    ): void {
        assert($classLike instanceof InterfaceType);
        $classLike->addExtend(InterfaceResolverInterface::class);
    }

    public function handleUnionResolver(
        ModuleInterface $module,
        UnionTypeDefinitionNode|UnionTypeExtensionNode $unionNode,
        ClassLike $classLike
    ): void {
        assert($classLike instanceof InterfaceType);
        $classLike->addExtend(UnionResolverInterface::class);
    }
}
