<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use GraphQL\Error\SyntaxError;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\Parser;

use function file_get_contents;

use const PHP_EOL;

final class DocumentNodeProvider implements DocumentNodeProviderInterface
{
    /**
     * @param iterable<string> $schemas
     */
    public function __construct(
        private readonly iterable $schemas,
    ) {
    }

    /**
     * @throws SyntaxError
     */
    public function getDocumentNode(): DocumentNode
    {
        $schemaContent = '';

        foreach ($this->schemas as $schema) {
            $schemaContent .= file_get_contents($schema) . PHP_EOL . PHP_EOL;
        }

        return Parser::parse($schemaContent);
    }
}
