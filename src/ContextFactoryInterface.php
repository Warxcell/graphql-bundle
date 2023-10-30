<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Server\OperationParams;

/**
 * @template T
 * @phpstan-import-type OperationType from OperationDefinitionNode
 */
interface ContextFactoryInterface
{
    /**
     * @param OperationType $operationType
     * @return T
     */
    public function createContext(
        OperationParams $params,
        DocumentNode $doc,
        string $operationType
    ): mixed;
}
