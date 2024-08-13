<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Events;

use GraphQL\Executor\ExecutionResult;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Type\Schema;

final readonly class OnExecuteDone
{
    public function __construct(
        public Schema $schema,
        public DocumentNode $node,
        public mixed $contextValue,
        public array $variables,
        public ?string $operationName,
        ExecutionResult $result
    ) {
    }
}
