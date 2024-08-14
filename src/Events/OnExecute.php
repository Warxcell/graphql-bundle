<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Events;

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Type\Schema;

/**
 * @phpstan-import-type OperationType from OperationDefinitionNode
 */
final readonly class OnExecute
{
    public function __construct(
        public Schema $schema,
        public DocumentNode $document,
        public mixed $contextValue,
        public ?array $variables,
        public ?string $operationName,
        /** @var OperationType */
        public ?string $operationType,
    ) {
    }
}
