<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use Closure;
use GraphQL\Error\DebugFlag;
use GraphQL\Error\Error;
use GraphQL\Error\FormattedError;
use GraphQL\Error\SyntaxError;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Promise\Adapter\SyncPromiseAdapter;
use GraphQL\Executor\Promise\Promise;
use GraphQL\Executor\Promise\PromiseAdapter;
use GraphQL\GraphQL;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\Parser;
use GraphQL\Server\OperationParams;
use GraphQL\Server\RequestError;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;
use GraphQL\Utils\Utils;
use JsonException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

use function array_map;
use function count;
use function html_entity_decode;
use function is_array;
use function is_string;
use function json_encode;
use function parse_str;
use function stripos;

use const JSON_THROW_ON_ERROR;

final class RequestHandler implements RequestHandlerInterface
{
    private readonly Closure $persistedQueryLoader;
    private readonly Closure $errorHandlerApplier;

    public function __construct(
        private readonly Schema $schema,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly PromiseAdapter $promiseAdapter,
        private readonly ?ContextFactoryInterface $contextFactory,
        ErrorHandlerInterface $errorsHandler,
        bool $debug,
    ) {
        $errorsHandlerDecorated = static function (
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

        $this->errorHandlerApplier = static function (ExecutionResult $result) use ($errorsHandlerDecorated, $debug): ExecutionResult {
            $result->setErrorsHandler($errorsHandlerDecorated);

            $result->setErrorFormatter(
                FormattedError::prepareFormatter(
                    null,
                    $debug ? DebugFlag::INCLUDE_TRACE | DebugFlag::INCLUDE_DEBUG_MESSAGE : DebugFlag::NONE
                )
            );

            return $result;
        };;

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
        $parsedBody = $this->parsePsrRequest($request);
        $response = $this->responseFactory->createResponse();

        if (is_array($parsedBody)) {
            $result = [];

            foreach ($parsedBody as $operation) {
                $result[] = $this->executeOperation($operation, $request, $response);
            }

            $result = $this->promiseAdapter->all($result);
        } else {
            $result = $this->executeOperation($parsedBody, $request, $response);
        }

        if ($this->promiseAdapter instanceof SyncPromiseAdapter) {
            $result = $this->promiseAdapter->wait($result);
        }

        $stream = $this->streamFactory->createStream();

        return $result->then(
            fn ($actualResult): ResponseInterface => $this->doConvertToPsrResponse($actualResult, $response, $stream)
        );
    }

    /**
     * Converts PSR-7 request to OperationParams or an array thereof.
     *
     * @return OperationParams|array<OperationParams>
     *
     * @throws RequestError
     * @throws JsonException
     *
     * @api
     */
    private function parsePsrRequest(RequestInterface $request): OperationParams|array
    {
        if ($request->getMethod() === 'GET') {
            $bodyParams = [];
        } else {
            $contentType = $request->getHeader('content-type');

            if (!isset($contentType[0])) {
                throw new RequestError('Missing "Content-Type" header');
            }

            if (stripos($contentType[0], 'application/graphql') !== false) {
                $bodyParams = ['query' => (string)$request->getBody()];
            } elseif (stripos($contentType[0], 'application/json') !== false) {
                $bodyParams = $request->getParsedBody();

                if (!is_array($bodyParams)) {
                    throw new RequestError(
                        'Expected JSON object or array for "application/json" request, got: ' . Utils::printSafeJson($bodyParams)
                    );
                }
            } else {
                $bodyParams = $request->getParsedBody();
                $bodyParams ??= $this->decodeContent((string)$request->getBody(), $contentType[0]);
            }
        }

        parse_str(html_entity_decode($request->getUri()->getQuery()), $queryParams);

        return $this->parseRequestParams(
            $request->getMethod(),
            $bodyParams,
            $queryParams
        );
    }

    /**
     * @return array<string, mixed>
     * @throws RequestError
     *
     */
    private function decodeContent(string $rawBody, string $contentType): array
    {
        parse_str($rawBody, $bodyParams);

        if (!is_array($bodyParams)) {
            throw new RequestError('Unexpected content type: ' . Utils::printSafeJson($contentType));
        }

        return $bodyParams;
    }

    private function parseRequestParams(string $method, array $bodyParams, array $queryParams)
    {
        if ($method === 'GET') {
            return OperationParams::create($queryParams, true);
        }

        if ($method === 'POST') {
            if (isset($bodyParams[0])) {
                $operations = [];
                foreach ($bodyParams as $entry) {
                    $operations[] = OperationParams::create($entry);
                }

                return $operations;
            }

            return OperationParams::create($bodyParams);
        }

        throw new RequestError('HTTP Method "' . $method . '" is not supported');
    }

    /**
     * @param ExecutionResult|array<ExecutionResult> $result
     * @throws JsonException
     */
    private function doConvertToPsrResponse(
        ExecutionResult|array $result,
        ResponseInterface $response,
        StreamInterface $writableBodyStream
    ): ResponseInterface {
        $writableBodyStream->write(json_encode($result, JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withBody($writableBodyStream);
    }

    private function validateOperationParams(OperationParams $params): array
    {
        $errors = [];
        $query = $params->query ?? '';
        $queryId = $params->queryId ?? '';
        if ($query === '' && $queryId === '') {
            $errors[] = new RequestError('GraphQL Request must include at least one of those two parameters: "query" or "queryId"');
        }

        if (!is_string($query)) {
            $errors[] = new RequestError(
                'GraphQL Request parameter "query" must be string, but got '
                . Utils::printSafeJson($params->query)
            );
        }

        if (!is_string($queryId)) {
            $errors[] = new RequestError(
                'GraphQL Request parameter "queryId" must be string, but got '
                . Utils::printSafeJson($params->queryId)
            );
        }

        if ($params->operation !== null && !is_string($params->operation)) {
            $errors[] = new RequestError(
                'GraphQL Request parameter "operation" must be string, but got '
                . Utils::printSafeJson($params->operation)
            );
        }

        if ($params->variables !== null && (!is_array($params->variables) || isset($params->variables[0]))) {
            $errors[] = new RequestError(
                'GraphQL Request parameter "variables" must be object or JSON string parsed to object, but got '
                . Utils::printSafeJson($params->originalInput['variables'])
            );
        }

        return $errors;
    }

    private function executeOperation(OperationParams $op, ServerRequestInterface $request, ResponseInterface $response): Promise
    {
        try {
            $errors = $this->validateOperationParams($op);

            if (count($errors) > 0) {
                $locatedErrors = array_map(
                    [Error::class, 'createLocatedError'],
                    $errors
                );

                return $this->promiseAdapter->createFulfilled(
                    new ExecutionResult(null, $locatedErrors)
                );
            }

            $doc = $op->queryId !== null && $op->query === null ? ($this->persistedQueryLoader)($op->queryId, $op) : $op->query;

            if (!$doc instanceof DocumentNode) {
                // TODO cache maybe?
                $doc = Parser::parse($doc);
            }

            $operationAST = AST::getOperationAST($doc, $op->operation);

            if ($operationAST === null) {
                throw new RequestError('Failed to determine operation type');
            }

            $operationType = $operationAST->operation;
            if ($operationType !== 'query' && $op->readOnly) {
                throw new RequestError('GET supports only query operation');
            }

            $root = null;
            $context = $this->contextFactory?->createContext($op, $op->query, $operationType, $request, $response);
            $result = GraphQL::promiseToExecute(
                promiseAdapter: $this->promiseAdapter,
                schema: $this->schema,
                source: $doc,
                rootValue: $root,
                context: $context,
                variableValues: $op->variables,
                operationName: $op->operation,
                fieldResolver: null,
                validationRules: null
            );
        } catch (RequestError $e) {
            $result = $this->promiseAdapter->createFulfilled(
                new ExecutionResult(null, [Error::createLocatedError($e)])
            );
        } catch (Error $e) {
            $result = $this->promiseAdapter->createFulfilled(
                new ExecutionResult(null, [$e])
            );
        }

        return $result->then($this->errorHandlerApplier);
    }
}

