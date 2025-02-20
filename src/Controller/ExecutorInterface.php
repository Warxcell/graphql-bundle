<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Controller;

use Arxy\GraphQL\QueryContainer;
use GraphQL\Executor\ExecutionResult;

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
