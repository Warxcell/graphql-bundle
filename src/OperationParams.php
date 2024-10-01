<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

final readonly class OperationParams
{
    public function __construct(
        public string $query,
        public string $queryCacheKey,
        public ?string $operationName,
        public ?array $variables,
        public ?array $extensions,
        public bool $readOnly
    ) {
    }
}
