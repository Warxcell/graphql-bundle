<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use Closure;
use GraphQL\Error\DebugFlag;
use GraphQL\Executor\Promise\PromiseAdapter;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Server\Helper;
use GraphQL\Server\OperationParams;
use GraphQL\Server\RequestError;
use GraphQL\Server\ServerConfig;
use GraphQL\Server\StandardServer;
use GraphQL\Type\Schema;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

final class RequestHandler implements RequestHandlerInterface
{
    private readonly StandardServer $server;
    private readonly Helper $helper;

    public function __construct(
        Schema $schema,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        ErrorHandlerInterface $errorsHandler,
        bool $debug,
        PromiseAdapter $promiseAdapter,
        ?ContextFactoryInterface $contextFactory,
    ) {
        $this->helper = new Helper();

        $this->server = new StandardServer(
            ServerConfig::create()
                ->setRootValue(
                    static fn (
                        OperationParams $params,
                        DocumentNode $doc,
                        string $operationType
                    ): mixed => null
                )
                ->setContext($contextFactory ? [$contextFactory, 'createContext'] : null)
                ->setSchema($schema)
                ->setErrorsHandler(static function (
                    array $errors,
                    Closure $formatter
                ) use ($errorsHandler): array {
                    return $errorsHandler->handleErrors($errors, static function (Throwable $error) use (
                        $formatter
                    ): array {
                        $formatted = $formatter($error);

                        $previous = $error->getPrevious();
                        if ($previous instanceof ExceptionInterface) {
                            $formatted['extensions'] += $previous->getExtensions();
                            $formatted['extensions']['category'] = $previous->getCategory();
                        }

                        return $formatted;
                    });
                })
                ->setDebugFlag($debug ? DebugFlag::INCLUDE_TRACE | DebugFlag::INCLUDE_DEBUG_MESSAGE : DebugFlag::NONE)
                ->setPromiseAdapter($promiseAdapter)
                ->setPersistedQueryLoader(static function (string $queryId, OperationParams $operationParams) {
                    return $operationParams->query;
                })
        );
    }

    /**
     * @throws RequestError
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $parsedBody = $this->helper->parsePsrRequest($request);

        $result = $this->server->executeRequest($parsedBody);

        $response = $this->responseFactory->createResponse();
        $stream = $this->streamFactory->createStream();

        return $this->helper->toPsrResponse($result, $response, $stream);
    }
}

