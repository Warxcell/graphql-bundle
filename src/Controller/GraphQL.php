<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Controller;

use Arxy\GraphQL\ContextFactoryInterface;
use Arxy\GraphQL\ErrorsHandlerInterface;
use Arxy\GraphQL\ExceptionInterface;
use Arxy\GraphQL\ExtensionsAwareContext;
use GraphQL\Error\DebugFlag;
use GraphQL\Error\Error;
use GraphQL\Error\FormattedError;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Executor;
use GraphQL\Executor\Promise\Adapter\SyncPromiseAdapter;
use GraphQL\Executor\Promise\Promise;
use GraphQL\Executor\Promise\PromiseAdapter;
use GraphQL\Language\Parser;
use GraphQL\Server\OperationParams;
use GraphQL\Server\RequestError;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;
use GraphQL\Utils\Utils;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\QueryComplexity;
use JsonException;
use LogicException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function array_map;
use function assert;
use function explode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function md5;
use function parse_str;
use function str_contains;

use const JSON_THROW_ON_ERROR;

final class GraphQL
{
    public function __construct(
        private readonly PromiseAdapter $promiseAdapter,
        private readonly bool $debug,
        private readonly ErrorsHandlerInterface $errorsHandler,
        private readonly Schema $schema,
        private readonly CacheItemPoolInterface $queryCache,
        private readonly ContextFactoryInterface|null $contextFactory = null,
    ) {
    }

    /**
     * @throws RequestError
     * @throws JsonException
     */
    public function __invoke(Request $request): Response
    {
        try {
            $params = $this->parseRequest($request);

            $result = $this->executeOperation($params);


            if ($this->promiseAdapter instanceof SyncPromiseAdapter) {
                $result = $this->promiseAdapter->wait($result);
            } else {
                throw new LogicException('Promise not supported');
            }
        } catch (Throwable $throwable) {
            $result = new ExecutionResult(null, [
                Error::createLocatedError($throwable),
            ]);
        }

        $result->setErrorsHandler(function (
            array $errors,
            callable $formatter
        ): array {
            return $this->errorsHandler->handleErrors($errors, static function (Throwable $error) use (
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
        });

        $result->setErrorFormatter(
            FormattedError::prepareFormatter(
                formatter: null,
                debug: $this->debug ? DebugFlag::INCLUDE_TRACE | DebugFlag::INCLUDE_DEBUG_MESSAGE : DebugFlag::NONE
            )
        );

        return $this->resultToResponse($result);
    }

    private function parseRequest(Request $request): OperationParams
    {
        if ($request->getMethod() === 'GET') {
            $bodyParams = [];
        } else {
            $contentType = $request->headers->get('Content-Type');

            if (null === $contentType) {
                throw new RequestError('Missing "Content-Type" header');
            }

            if (str_contains($contentType, 'application/graphql')) {
                $bodyParams = ['query' => (string)$request->getContent()];
            } elseif (str_contains($contentType, 'application/json')) {
                $bodyParams = $this->decodeJson((string)$request->getContent());
            } elseif (str_contains($contentType, 'multipart/form-data')) {
                $map = $this->decodeArray($request->request, 'map');
                $bodyParams = $this->decodeArray($request->request, 'operations');

                foreach ($map as $fileKey => $locations) {
                    foreach ($locations as $location) {
                        $items = &$bodyParams;
                        foreach (explode('.', $location) as $key) {
                            if (!isset($items[$key]) || !is_array($items[$key])) {
                                $items[$key] = [];
                            }
                            $items = &$items[$key];
                        }

                        $items = $request->files->get((string)$fileKey);
                    }
                }
            } else {
                $bodyParams = $this->decodeContent((string)$request->getContent());
            }
        }

        return $this->parseRequestParams(
            $request->getMethod(),
            $bodyParams,
            $request->query->all()
        );
    }

    protected function decodeJson(string $rawBody): array
    {
        try {
            $bodyParams = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($bodyParams)) {
                $notArray = Utils::printSafeJson($bodyParams);
                throw new RequestError(
                    "Expected JSON object or array for \"application/json\" request, got: {$notArray}"
                );
            }
        } catch (JsonException $exception) {
            throw new RequestError('Expected JSON object or array for "application/json" request', 0, $exception);
        }

        return $bodyParams;
    }

    /**
     * @throws RequestError
     * @throws JsonException
     */
    private function decodeArray(InputBag $bodyParams, string $key): array
    {
        $value = $bodyParams->get($key);
        if (!is_string($value)) {
            throw new RequestError("The request must define a `$key`");
        }

        $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new RequestError("The `$key` key must be a JSON encoded array");
        }

        return $value;
    }

    /**
     * @return array<mixed>
     * @throws RequestError
     *
     */
    protected function decodeContent(string $rawBody): array
    {
        parse_str($rawBody, $bodyParams);

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

        throw new RequestError("HTTP Method \"{$method}\" is not supported");
    }

    private function executeOperation(OperationParams $op): Promise
    {
        return $this->promiseToExecuteOperation($this->promiseAdapter, $op);
    }

    private function promiseToExecuteOperation(
        PromiseAdapter $promiseAdapter,
        OperationParams $op
    ): Promise {
        $context = null;
        try {
            $errors = $this->validateOperationParams($op);

            if ($errors !== []) {
                $locatedErrors = array_map(
                    [Error::class, 'createLocatedError'],
                    $errors
                );

                return $promiseAdapter->createFulfilled(
                    new ExecutionResult(null, $locatedErrors)
                );
            }


            $cacheItem = $this->queryCache->getItem(md5($op->query));

            if ($cacheItem->isHit()) {
                $documentNode = AST::fromArray($cacheItem->get());
            } else {
                $documentNode = Parser::parse($op->query);

                $queryComplexity = DocumentValidator::getRule(QueryComplexity::class);
                assert(
                    $queryComplexity instanceof QueryComplexity,
                    'should not register a different rule for QueryComplexity'
                );

                $queryComplexity->setRawVariableValues($op->variables);


                $validationErrors = DocumentValidator::validate($this->schema, $documentNode);

                if ($validationErrors !== []) {
                    return $promiseAdapter->createFulfilled(
                        new ExecutionResult(null, $validationErrors)
                    );
                } else {
                    $cacheItem->set(AST::toArray($documentNode));
                    $this->queryCache->save($cacheItem);
                }
            }

            $operationAST = AST::getOperationAST($documentNode, $op->operation);

            if ($operationAST === null) {
                throw new RequestError('Failed to determine operation type');
            }

            $operationType = $operationAST->operation;
            if ($operationType !== 'query' && $op->readOnly) {
                throw new RequestError('GET supports only query operation');
            }

            $context = $this->contextFactory?->createContext($op, $documentNode, $operationType);
            $result = Executor::promiseToExecute(
                promiseAdapter: $promiseAdapter,
                schema: $this->schema,
                documentNode: $documentNode,
                contextValue: $context,
                variableValues: $op->variables,
                operationName: $op->operation,
            );
        } catch (RequestError $e) {
            $result = $promiseAdapter->createFulfilled(
                new ExecutionResult(null, [Error::createLocatedError($e)])
            );
        } catch (Error $e) {
            $result = $promiseAdapter->createFulfilled(
                new ExecutionResult(null, [$e])
            );
        }

        $applyErrorHandling = function (ExecutionResult $result) use ($context): ExecutionResult {
            if ($context instanceof ExtensionsAwareContext) {
                $result->extensions = $context->getExtensions();
            }

            return $result;
        };

        return $result->then($applyErrorHandling);
    }

    public function validateOperationParams(OperationParams $params): array
    {
        $errors = [];
        $query = $params->query ?? '';
        $queryId = $params->queryId ?? '';
        if ($query === '' && $queryId === '') {
            $errors[] = new RequestError(
                'GraphQL Request must include at least one of those two parameters: "query" or "queryId"'
            );
        }

        if (!\is_string($query)) {
            $errors[] = new RequestError(
                'GraphQL Request parameter "query" must be string, but got '
                .Utils::printSafeJson($params->query)
            );
        }

        if (!\is_string($queryId)) {
            $errors[] = new RequestError(
                'GraphQL Request parameter "queryId" must be string, but got '
                .Utils::printSafeJson($params->queryId)
            );
        }

        if ($params->operation !== null && !\is_string($params->operation)) {
            $errors[] = new RequestError(
                'GraphQL Request parameter "operation" must be string, but got '
                .Utils::printSafeJson($params->operation)
            );
        }

        if ($params->variables !== null && (!\is_array($params->variables) || isset($params->variables[0]))) {
            $errors[] = new RequestError(
                'GraphQL Request parameter "variables" must be object or JSON string parsed to object, but got '
                .Utils::printSafeJson($params->originalInput['variables'])
            );
        }

        return $errors;
    }

    /**
     * @throws JsonException
     */
    private function resultToResponse(ExecutionResult $result): Response
    {
        $response = new Response();
        $response->setContent(json_encode($result, JSON_THROW_ON_ERROR));
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }
}
