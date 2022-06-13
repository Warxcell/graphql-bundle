<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Codegen;

use Arxy\GraphQL\Enum;
use Arxy\GraphQL\InterfaceResolverInterface;
use Arxy\GraphQL\ResolverInterface;
use Arxy\GraphQL\ScalarResolverInterface;
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
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\EnumType;
use Nette\PhpGenerator\InterfaceType;

final class TypeDecorator implements TypeDecoratorInterface
{
    public function handleObject(
        ModuleInterface $module,
        ObjectTypeDefinitionNode|ObjectTypeExtensionNode $objectNode,
        ClassType $classLike
    ): void {
    }

    public function handleObjectFieldArgs(
        ModuleInterface $module,
        ObjectTypeDefinitionNode|ObjectTypeExtensionNode $objectNode,
        FieldDefinitionNode $fieldNode,
        ClassType $classLike
    ): void {
    }

    public function handleObjectResolverInterface(
        ModuleInterface $module,
        ObjectTypeDefinitionNode|ObjectTypeExtensionNode $objectNode,
        InterfaceType $classLike
    ): void {
        $classLike->addExtend(ResolverInterface::class);
    }

    public function handleObjectResolverImplementation(
        ModuleInterface $module,
        ObjectTypeDefinitionNode|ObjectTypeExtensionNode $objectNode,
        ClassType $classLike
    ): void {
    }

    public function handleInputObjectType(
        ModuleInterface $module,
        InputObjectTypeExtensionNode|InputObjectTypeDefinitionNode $definitionNode,
        ClassType $classLike
    ): void {
    }

    public function handleEnumType(
        ModuleInterface $module,
        EnumTypeDefinitionNode|EnumTypeExtensionNode $enumNode,
        EnumType $classLike
    ): void {
        $classLike->addAttribute(Enum::class);
    }

    public function handleScalarResolverInterface(
        ModuleInterface $module,
        ScalarTypeExtensionNode|ScalarTypeDefinitionNode $scalarNode,
        InterfaceType $classLike
    ): void {
        $classLike->addExtend(ScalarResolverInterface::class);
    }

    public function handleInterfaceResolverInterface(
        ModuleInterface $module,
        InterfaceTypeDefinitionNode|InterfaceTypeExtensionNode $interfaceNode,
        InterfaceType $classLike
    ): void {
        $classLike->addExtend(InterfaceResolverInterface::class);
    }

    public function handleUnionResolverInterface(
        ModuleInterface $module,
        UnionTypeDefinitionNode|UnionTypeExtensionNode $unionNode,
        InterfaceType $classLike
    ): void {
        $classLike->addExtend(UnionResolverInterface::class);
    }
}
