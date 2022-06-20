<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use Exception;
use GraphQL\Language\AST\DirectiveNode;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Utils\AST;

final class DirectiveHelper
{
    /**
     * @throws Exception
     */
    public static function getDirectiveValues(string $name, ResolveInfo $info): ?array
    {
        $node = $info->fieldDefinition->astNode;

        foreach ($node->directives as $directive) {
            assert($directive instanceof DirectiveNode);
            if ($directive->name->value === $name) {
                $argumentValueMap = [];
                foreach ($directive->arguments as $argumentNode) {
                    $argumentValueMap[$argumentNode->name->value] = AST::valueFromASTUntyped($argumentNode->value);
                }

                return $argumentValueMap;
            }
        }

        return null;
    }

}
