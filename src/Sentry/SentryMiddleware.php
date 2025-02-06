<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Sentry;

use Arxy\GraphQL\Controller\ExecutorInterface;
use Arxy\GraphQL\QueryContainer;
use GraphQL\Executor\ExecutionResult;
use Sentry\State\HubInterface;
use Sentry\State\Scope;

final readonly class SentryMiddleware implements ExecutorInterface
{
    public function __construct(
        private HubInterface $hub,
        private ExecutorInterface $executor
    ) {
    }

    public function execute(QueryContainer $queryContainer, mixed $context): ExecutionResult
    {
        $this->hub->configureScope(static function (Scope $scope) use ($queryContainer): void {
            $scope->setContext('GraphQL', [
                'operationName' => $queryContainer->operationDefinitionNode->name?->value,
                'operationType' => $queryContainer->operationDefinitionNode->operation,
            ]);

            $scope->setExtra('document', $queryContainer->query);
            $scope->setExtra('variables', $queryContainer->variables);
        });

        return $this->executor->execute($queryContainer, $context);
    }
}
