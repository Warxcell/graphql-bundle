<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

final readonly class OperationParams
{
    public function __construct(
        public ?string $query = null,
        public ?string $operationName = null,
        public ?array $variables = null,
        public ?array $extensions = null,
        public bool $readOnly = false
    ) {
    }
}
