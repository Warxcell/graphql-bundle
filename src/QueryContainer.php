<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\OperationDefinitionNode;

final readonly class QueryContainer
{
    public function __construct(
        public string $query,
        public string $cacheKey,
        public DocumentNode $documentNode,
        public OperationDefinitionNode $operationDefinitionNode,
        public ?array $variables,
    ) {
    }
}
