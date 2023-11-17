<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Server\OperationParams;

interface PersistedQueryLoader
{
    public function load(string $queryId, OperationParams $operation): string|DocumentNode;
}
