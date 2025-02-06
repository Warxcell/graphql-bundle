<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Controller;

use Arxy\GraphQL\Events\OnExecute;
use Arxy\GraphQL\Events\OnExecuteDone;
use Arxy\GraphQL\ExtensionsAwareContext;
use Arxy\GraphQL\QueryContainer;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Promise\Adapter\SyncPromiseAdapter;
use GraphQL\Type\Schema;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class Executor implements ExecutorInterface
{
    public function __construct(
        private Schema $schema,
        private SyncPromiseAdapter $promiseAdapter,
    ) {
    }

    public function execute(QueryContainer $queryContainer, mixed $context): ExecutionResult
    {
        $documentNode = $queryContainer->documentNode;
        $variables = $queryContainer->variables;
        $operationDefinitionNode = $queryContainer->operationDefinitionNode;
        $operationName = $operationDefinitionNode->name?->value;

        $result = \GraphQL\Executor\Executor::promiseToExecute(
            promiseAdapter: $this->promiseAdapter,
            schema: $this->schema,
            documentNode: $documentNode,
            contextValue: $context,
            variableValues: $variables,
            operationName: $operationName,
        );

        $result = $this->promiseAdapter->wait($result);

        if ($context instanceof ExtensionsAwareContext) {
            $result->extensions = $context->getExtensions();
        }

        return $result;
    }
}
