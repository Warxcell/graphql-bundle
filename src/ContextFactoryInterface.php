<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Server\OperationParams;

interface ContextFactoryInterface
{
    public function createContext(
        OperationParams $params,
        DocumentNode $doc,
        string $operationType
    ): mixed;
}
