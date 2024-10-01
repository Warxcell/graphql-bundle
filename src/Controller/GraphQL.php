<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Controller;

use Arxy\GraphQL\ErrorsHandlerInterface;
use Arxy\GraphQL\ExceptionInterface;
use Arxy\GraphQL\OperationParams;
use Closure;
use GraphQL\Error\DebugFlag;
use GraphQL\Error\Error;
use GraphQL\Error\FormattedError;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Promise\Adapter\SyncPromiseAdapter;
use GraphQL\Language\Parser;
use GraphQL\Server\RequestError;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;
use GraphQL\Utils\Utils;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\QueryComplexity;
use JsonException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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

final readonly class GraphQL
{
    private Closure $errorFormatter;
    private Closure $errorsHandler;

    public function __construct(
        private ExecutorInterface $executor,
        private SyncPromiseAdapter $promiseAdapter,
        bool $debug,
        ErrorsHandlerInterface $errorsHandler,
        private Schema $schema,
        private CacheItemPoolInterface $queryCache,
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

    /**
     * @throws RequestError
     * @throws JsonException
     */
    public function __invoke(Request $request): Response
    {
        try {
            $params = $this->parseRequest($request);
            $cacheItem = $this->queryCache->getItem($params->queryCacheKey);

            if ($cacheItem->isHit()) {
                $documentNode = AST::fromArray($cacheItem->get());
            } else {
                $documentNode = Parser::parse($params->query);

                $queryComplexity = DocumentValidator::getRule(QueryComplexity::class);
                assert(
                    $queryComplexity instanceof QueryComplexity,
                    'should not register a different rule for QueryComplexity'
                );

                $queryComplexity->setRawVariableValues($params->variables);
                $validationErrors = DocumentValidator::validate($this->schema, $documentNode);

                if ($validationErrors !== []) {
                    return $this->resultToResponse(new ExecutionResult(null, $validationErrors));
                } else {
                    $cacheItem->set(AST::toArray($documentNode));
                    $this->queryCache->save($cacheItem);
                }
            }

            $operationAST = AST::getOperationAST($documentNode, $params->operationName);

            if ($operationAST === null) {
                throw new RequestError('Failed to determine operation type');
            }

            $operationType = $operationAST->operation;

            if ($operationType !== 'query' && $params->readOnly) {
                throw new RequestError('GET supports only query operation');
            }

            $result = $this->executor->execute(
                $this->schema,
                $this->promiseAdapter,
                $params,
                $documentNode,
                $operationAST
            );

            return $this->resultToResponse($result);
        } catch (RequestError $e) {
            return $this->resultToResponse(new ExecutionResult(null, [Error::createLocatedError($e)]));
        } catch (Error $e) {
            return $this->resultToResponse(new ExecutionResult(null, [$e]));
        } catch (Throwable $throwable) {
            return $this->resultToResponse(new ExecutionResult(null, [
                Error::createLocatedError($throwable),
            ]));
        }
    }

    private function resultToResponse(ExecutionResult $result): Response
    {
        $result->setErrorsHandler($this->errorsHandler);
        $result->setErrorFormatter($this->errorFormatter);

        $content = json_encode($result, JSON_THROW_ON_ERROR);

        return $this->createResponse($content);
    }

    private function createResponse(string $content): Response
    {
        $response = new Response();
        $response->setContent($content);
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * @throws RequestError
     */
    private function parseRequest(Request $request): OperationParams
    {
        $method = $request->getMethod();

        switch ($method) {
            case 'GET':
                return $this->validateOperationParams($request->query->all(), true);

            case 'POST':
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
                    parse_str((string)$request->getContent(), $bodyParams);
                }

                return $this->validateOperationParams($bodyParams);

            default:
                throw new RequestError("HTTP Method \"{$method}\" is not supported");
        }
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
     */
    private function decodeArray(InputBag $bodyParams, string $key): array
    {
        $value = $bodyParams->get($key);

        if (!is_string($value)) {
            throw new RequestError("The request must define a `$key`");
        }

        try {
            $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RequestError("The `$key` key must be a JSON encoded array", previous: $exception);
        }

        if (!is_array($value)) {
            throw new RequestError("The `$key` key must be a JSON encoded array");
        }

        return $value;
    }

    /**
     * @throws RequestError
     */
    public function validateOperationParams(array $params, bool $readOnly = false): OperationParams
    {
        $query = $params['query'] ?? null;
        $operationName = $params['operationName'] ?? null;
        $variables = $params['variables'] ?? null;
        $extensions = $params['extensions'] ?? null;

        if (!is_string($query)) {
            throw new RequestError(
                'GraphQL Request parameter "query" must be string, but got '
                .Utils::printSafeJson($query)
            );
        }

        if ($operationName !== null && !is_string($operationName)) {
            throw new RequestError(
                'GraphQL Request parameter "operation" must be string, but got '
                .Utils::printSafeJson($operationName)
            );
        }

        if ($variables !== null && !is_array($variables)) {
            throw new RequestError(
                'GraphQL Request parameter "variables" must be object or JSON string parsed to object, but got '
                .Utils::printSafeJson($variables)
            );
        }

        return new OperationParams($query, md5($query), $operationName, $variables, $extensions, $readOnly);
    }
}
