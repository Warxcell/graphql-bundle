<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use Exception;
use GraphQL\Language\AST\DirectiveNode;
use GraphQL\Language\AST\NodeList;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Utils\AST;
use GraphQL\Utils\Utils;

final class DirectiveHelper
{
    /**
     * @throws Exception
     */
    public static function getDirectiveValues(string $name, ResolveInfo $info): ?array
    {
        $node = $info->fieldDefinition->astNode;

        if (isset($node->directives) && $node->directives instanceof NodeList) {
            $directive = Utils::find(
                $node->directives,
                static function (DirectiveNode $directive) use ($name): bool {
                    return $directive->name->value === $name;
                }
            );

            if (!$directive) {
                return null;
            }

            assert($directive instanceof DirectiveNode);

            $argumentValueMap = [];
            foreach ($directive->arguments as $argumentNode) {
                $argumentValueMap[$argumentNode->name->value] = AST::valueFromASTUntyped($argumentNode->value);
            }

            return $argumentValueMap;
        }

        return null;
    }

}
