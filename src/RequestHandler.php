<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use Closure;
use GraphQL\Error\DebugFlag;
use GraphQL\Error\SyntaxError;
use GraphQL\Executor\Promise\PromiseAdapter;
use GraphQL\Server\Helper;
use GraphQL\Server\OperationParams;
use GraphQL\Server\RequestError;
use GraphQL\Server\ServerConfig;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

use function is_array;

final class RequestHandler implements RequestHandlerInterface
{
    private readonly Helper $helper;
    private readonly Closure $errorHandler;
    private readonly Closure $persistedQueryLoader;

    public function __construct(
        private readonly Schema $schema,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        ErrorHandlerInterface $errorsHandler,
        private readonly bool $debug,
        private readonly PromiseAdapter $promiseAdapter,
        private readonly ?ContextFactoryInterface $contextFactory,
    ) {
        $this->helper = new Helper();
        $this->errorHandler = static function (
            array $errors,
            Closure $formatter
        ) use ($errorsHandler): array {
            return $errorsHandler->handleErrors($errors, static function (Throwable $error) use (
                $formatter
            ): array {
                $formatted = $formatter($error);

                $previous = $error->getPrevious();
                if ($previous instanceof ExceptionInterface) {
                    $formatted['extensions'] = [...($formatted['extensions'] ?? []), 'category' => $previous->getCategory()];
                }

                return $formatted;
            });
        };

        $this->persistedQueryLoader = static function (string $queryId, OperationParams $operationParams) {
            return $operationParams->query;
        };
    }

    /**
     * @throws RequestError
     * @throws SyntaxError
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $parsedBody = $this->helper->parsePsrRequest($request);
        $response = $this->responseFactory->createResponse();
        $operationAST = AST::getOperationAST($parsedBody->query, $parsedBody->operation);

        if ($operationAST === null) {
            throw new RequestError('Failed to determine operation type');
        }

        $operationType = $operationAST->operation;

        $context = $this->contextFactory?->createContext($parsedBody, $parsedBody->query, $operationType, $request, $response);
        $config = ServerConfig::create()
            ->setRootValue(null)
            ->setContext($context)
            ->setSchema($this->schema)
            ->setErrorsHandler($this->errorHandler)
            ->setDebugFlag($this->debug ? DebugFlag::INCLUDE_TRACE | DebugFlag::INCLUDE_DEBUG_MESSAGE : DebugFlag::NONE)
            ->setPromiseAdapter($this->promiseAdapter)
            ->setPersistedQueryLoader($this->persistedQueryLoader);

        if (is_array($parsedBody)) {
            $result = $this->helper->executeBatch($config, $parsedBody);
        } else {
            $result = $this->helper->executeOperation($config, $parsedBody);
        }

        $stream = $this->streamFactory->createStream();

        return $this->helper->toPsrResponse($result, $response, $stream);
    }
}

