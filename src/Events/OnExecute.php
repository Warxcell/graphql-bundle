<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Events;

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Type\Schema;

final readonly class OnExecute
{
    public function __construct(
        public Schema $schema,
        public DocumentNode $node,
        public mixed $contextValue,
        public array $variables,
        public ?string $operationName
    ) {
    }
}
