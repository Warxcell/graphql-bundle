<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Security;

use Arxy\GraphQL\DocumentVisitorCompilerPass;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use GraphQL\Language\AST\ObjectTypeExtensionNode;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Language\Visitor;
use LogicException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class SecurityCompilerPass extends DocumentVisitorCompilerPass
{
    protected function processDocumentNode(ContainerBuilder $container, DocumentNode $documentNode): void
    {
        /** @var array<string, array<string, string>> $roles */
        $roles = [];

        $visitor = function (
            ObjectTypeDefinitionNode|ObjectTypeExtensionNode $definitionNode
        ) use (
            &$roles
        ) {
            foreach ($definitionNode->fields as $field) {
                foreach ($field->directives as $directive) {
                    if ($directive->name->value === 'isGranted') {
                        foreach ($directive->arguments as $argument) {
                            if ($argument->name->value === 'role') {
                                if (!$argument->value instanceof StringValueNode) {
                                    throw new LogicException('Role argument not String');
                                }
                                $roles[$definitionNode->name->value][$field->name->value] = $argument->value->value;

                                continue 3;
                            }
                        }
                    }
                }
            }
        };
        Visitor::visit($documentNode, [
            'enter' => [
                NodeKind::OBJECT_TYPE_DEFINITION => $visitor,
                NodeKind::OBJECT_TYPE_EXTENSION => $visitor,
            ],
        ]);

        $securityDefinition = $container->getDefinition(SecurityMiddleware::class);
        $securityDefinition->setArgument('$roles', $roles);
    }
}
