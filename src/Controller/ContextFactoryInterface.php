<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Controller;

use Arxy\GraphQL\QueryContainer;
use GraphQL\Language\AST\OperationDefinitionNode;
use Symfony\Component\HttpFoundation\Request;

/**
 * @template T
 * @phpstan-import-type OperationType from OperationDefinitionNode
 */
interface ContextFactoryInterface
{
    /**
     * @return T
     */
    public function createContext(QueryContainer $queryContainer, Request $request): mixed;
}
