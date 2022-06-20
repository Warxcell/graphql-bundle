<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Codegen;

use Arxy\GraphQL\InterfaceResolverInterface;
use Arxy\GraphQL\ResolverInterface;
use Arxy\GraphQL\ScalarResolverInterface;
use Arxy\GraphQL\UnionResolverInterface;
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
        ObjectTypeDefinitionNode|ObjectTypeExtensionNode $objectNode,
        InterfaceType $classLike
    ): void {
        $classLike->addExtend(ResolverInterface::class);
    }

    public function handleScalarResolverInterface(
        array $documents,
        Module $module,
        ScalarTypeExtensionNode|ScalarTypeDefinitionNode $scalarNode,
        InterfaceType $classLike
    ): void {
        $classLike->addExtend(ScalarResolverInterface::class);
    }

    public function handleInterfaceResolverInterface(
        array $documents,
        Module $module,
        InterfaceTypeDefinitionNode|InterfaceTypeExtensionNode $interfaceNode,
        InterfaceType $classLike
    ): void {
        $classLike->addExtend(InterfaceResolverInterface::class);
    }

    public function handleUnionResolverInterface(
        array $documents,
        Module $module,
        UnionTypeDefinitionNode|UnionTypeExtensionNode $unionNode,
        InterfaceType $classLike
    ): void {
        $classLike->addExtend(UnionResolverInterface::class);
    }
}
