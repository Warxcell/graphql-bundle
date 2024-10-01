<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Controller;

use Arxy\GraphQL\ContextFactoryInterface;
use Arxy\GraphQL\Events\OnExecute;
use Arxy\GraphQL\Events\OnExecuteDone;
use Arxy\GraphQL\ExtensionsAwareContext;
use Arxy\GraphQL\OperationParams;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Promise\Adapter\SyncPromiseAdapter;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Type\Schema;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class Executor implements ExecutorInterface
{
    public function __construct(
        private EventDispatcherInterface $dispatcher,
        private ?ContextFactoryInterface $contextFactory = null,
    ) {
    }

    public function execute(
        Schema $schema,
        SyncPromiseAdapter $promiseAdapter,
        OperationParams $params,
        DocumentNode $documentNode,
        OperationDefinitionNode $operationDefinitionNode
    ): ExecutionResult {
        $operationType = $operationDefinitionNode->operation;
        $context = $this->contextFactory?->createContext($documentNode, $operationType);

        $this->dispatcher->dispatch(
            new OnExecute(
                $schema,
                $documentNode,
                $context,
                $params->variables,
                $params->operationName,
                $operationType
            )
        );

        $result = \GraphQL\Executor\Executor::promiseToExecute(
            promiseAdapter: $promiseAdapter,
            schema: $schema,
            documentNode: $documentNode,
            contextValue: $context,
            variableValues: $params->variables,
            operationName: $params->operationName,
        );

        $result = $promiseAdapter->wait($result);

        $this->dispatcher->dispatch(
            new OnExecuteDone(
                $schema,
                $documentNode,
                $context,
                $params->variables,
                $params->operationName,
                $operationType,
                $result
            )
        );

        if ($context instanceof ExtensionsAwareContext) {
            $result->extensions = $context->getExtensions();
        }

        return $result;
    }
}
