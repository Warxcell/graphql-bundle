<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Controller;

use Arxy\GraphQL\OperationParams;
use Arxy\GraphQL\QueryContainer;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Promise\Adapter\SyncPromiseAdapter;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Type\Schema;

/**
 * @template T
 */
interface ExecutorInterface
{
    /**
     * @param T $context
     */
    public function execute(QueryContainer $queryContainer, mixed $context): ExecutionResult;
}
