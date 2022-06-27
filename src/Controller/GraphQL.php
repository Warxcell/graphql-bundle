<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Controller;

use Arxy\GraphQL\ContextFactoryInterface;
use Arxy\GraphQL\ErrorHandlerInterface;
use Arxy\GraphQL\ExceptionInterface;
use Closure;
use GraphQL\Error\DebugFlag;
use GraphQL\Executor\Promise\PromiseAdapter;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Server\Helper;
use GraphQL\Server\OperationParams;
use GraphQL\Server\ServerConfig;
use GraphQL\Server\StandardServer;
use GraphQL\Type\Schema;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function json_decode;

use const JSON_THROW_ON_ERROR;

final class GraphQL
{
    private readonly Closure $handler;

    public function __construct(
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        Schema $schema,
        PromiseAdapter $promiseAdapter,
        bool $debug,
        ErrorHandlerInterface $errorsHandler,
        ?ContextFactoryInterface $contextFactory,
    ) {
        $server = new StandardServer(
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
        $helper = new Helper();
        $this->handler = static function (ServerRequestInterface $request) use (
            $server,
            $responseFactory,
            $streamFactory,
            $helper
        ) {
            $parsedBody = $helper->parsePsrRequest($request);

            $result = $server->executeRequest($parsedBody);

            $response = $responseFactory->createResponse();
            $stream = $streamFactory->createStream();

            return $helper->toPsrResponse($result, $response, $stream);
        };
    }

    public function __invoke(
        Request $request,
        HttpFoundationFactoryInterface $psrToSymfony,
        PsrHttpFactory $symfonyToPsr,
    ): Response {
        $psrRequest = $symfonyToPsr->createRequest($request);

        if ($psrRequest->getHeader('Content-Type')[0] === 'application/json') {
            $psrRequest = $psrRequest->withParsedBody(
                json_decode($psrRequest->getBody()->getContents(), true, JSON_THROW_ON_ERROR)
            );
        }

        return $psrToSymfony->createResponse(($this->handler)($psrRequest));
    }
}
