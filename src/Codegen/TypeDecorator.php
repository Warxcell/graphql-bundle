<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Codegen;

use Arxy\GraphQL\ResolverInterface;
use Arxy\GraphQLCodegen\AbstractTypeDecorator;
use Arxy\GraphQLCodegen\Module;
use GraphQL\Language\AST\InterfaceTypeDefinitionNode;
use GraphQL\Language\AST\InterfaceTypeExtensionNode;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use GraphQL\Language\AST\ObjectTypeExtensionNode;
use GraphQL\Language\AST\ScalarTypeDefinitionNode;
use GraphQL\Language\AST\ScalarTypeExtensionNode;
use GraphQL\Language\AST\UnionTypeDefinitionNode;
use GraphQL\Language\AST\UnionTypeExtensionNode;
use Nette\PhpGenerator\InterfaceType;

final class TypeDecorator extends AbstractTypeDecorator
{
    public function handleObjectResolverInterface(
        array $documents,
        Module $module,
        ObjectTypeDefinitionNode|ObjectTypeExtensionNode $definitionNode,
        InterfaceType $classLike
    ): void {
        $classLike->addExtend(ResolverInterface::class);
    }

    public function handleScalarResolverInterface(
        array $documents,
        Module $module,
        ScalarTypeExtensionNode|ScalarTypeDefinitionNode $definitionNode,
        InterfaceType $classLike
    ): void {
        $classLike->addExtend(ResolverInterface::class);
    }

    public function handleInterfaceResolverInterface(
        array $documents,
        Module $module,
        InterfaceTypeDefinitionNode|InterfaceTypeExtensionNode $definitionNode,
        InterfaceType $classLike
    ): void {
        $classLike->addExtend(ResolverInterface::class);
    }

    public function handleUnionResolverInterface(
        array $documents,
        Module $module,
        UnionTypeDefinitionNode|UnionTypeExtensionNode $definitionNode,
        InterfaceType $classLike
    ): void {
        $classLike->addExtend(ResolverInterface::class);
    }
}
