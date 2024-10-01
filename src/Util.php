<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use GraphQL\Language\AST\DirectiveNode;
use GraphQL\Language\AST\NodeList;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;

final readonly class Util
{
    /**
     * @param NodeList<DirectiveNode> $directives
     * @return iterable<string, iterable<string, mixed>>
     */
    public static function getDirectives(
        NodeList $directives,
        Schema $schema,
        ?array $variables
    ): ?iterable {
        foreach ($directives as $directive) {
            yield $directive->name->value => self::getDirectiveArguments($directive, $schema, $variables);
        }
    }

    public static function getDirectiveArguments(
        DirectiveNode $directiveNode,
        Schema $schema,
        ?array $variables
    ): iterable {
        foreach ($directiveNode->arguments as $argument) {
            yield $argument->name->value => AST::valueFromAST(
                $argument->value,
                $schema->getType($argument->name->value),
                $variables
            );
        }
    }
}
