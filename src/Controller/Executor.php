<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Controller;

use Arxy\GraphQL\ErrorsHandlerInterface;
use Arxy\GraphQL\Events\OnExecute;
use Arxy\GraphQL\Events\OnExecuteDone;
use Arxy\GraphQL\ExceptionInterface;
use Arxy\GraphQL\ExtensionsAwareContext;
use Arxy\GraphQL\QueryContainer;
use Closure;
use GraphQL\Error\DebugFlag;
use GraphQL\Error\FormattedError;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Promise\Adapter\SyncPromiseAdapter;
use GraphQL\Type\Schema;
use Throwable;

/**
 * @implements ExecutorInterface<mixed>
 */
final readonly class Executor implements ExecutorInterface
{
    private Closure $errorFormatter;
    private Closure $errorsHandler;

    public function __construct(
        private Schema $schema,
        private SyncPromiseAdapter $promiseAdapter,
        ErrorsHandlerInterface $errorsHandler,
        bool $debug,
    ) {
        $this->errorFormatter = FormattedError::prepareFormatter(
            formatter: null,
            debug: $debug ? DebugFlag::INCLUDE_TRACE | DebugFlag::INCLUDE_DEBUG_MESSAGE : DebugFlag::NONE
        );

        $this->errorsHandler = function (
            array $errors,
            callable $formatter
        ) use ($errorsHandler): array {
            return $errorsHandler->handleErrors($errors, static function (Throwable $error) use (
                $formatter
            ): array {
                $formatted = $formatter($error);

                $previous = $error->getPrevious();

                if ($previous instanceof ExceptionInterface) {
                    $formatted['extensions'] = [
                        ...($formatted['extensions'] ?? []),
                        'category' => $previous->getCategory(),
                    ];
                }

                return $formatted;
            });
        };
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

        $result->setErrorsHandler($this->errorsHandler);
        $result->setErrorFormatter($this->errorFormatter);

        if ($context instanceof ExtensionsAwareContext) {
            $result->extensions = $context->getExtensions();
        }

        return $result;
    }
}
