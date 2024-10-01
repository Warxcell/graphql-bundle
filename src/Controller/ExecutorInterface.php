<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Controller;

use Arxy\GraphQL\OperationParams;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Promise\Adapter\SyncPromiseAdapter;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Type\Schema;

interface ExecutorInterface
{
    public function execute(
        Schema $schema,
        SyncPromiseAdapter $promiseAdapter,
        OperationParams $params,
        DocumentNode $documentNode,
        OperationDefinitionNode $operationDefinitionNode
    ): ExecutionResult;
}