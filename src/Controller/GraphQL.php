<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Controller;

use Arxy\GraphQL\OperationParams;
use Arxy\GraphQL\QueryContainerFactoryInterface;
use Arxy\GraphQL\QueryError;
use GraphQL\Error\Error;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Server\RequestError;
use GraphQL\Utils\Utils;
use JsonException;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function explode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function parse_str;
use function str_contains;

use const JSON_THROW_ON_ERROR;

final readonly class GraphQL
{
    public function __construct(
        /** @var ExecutorInterface<mixed> */
        private ExecutorInterface $executor,
        private QueryContainerFactoryInterface $queryContainerFactory,
        private ?ContextFactoryInterface $contextFactory = null,
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
        } catch (RequestError $e) {
            return $this->resultToResponse(new ExecutionResult(null, [Error::createLocatedError($e)]));
        }
        try {
            $queryContainer = $this->queryContainerFactory->create($params);
        } catch (QueryError $error) {
            return $this->resultToResponse(new ExecutionResult(null, $error->errors));
        }

        $context = $this->contextFactory?->createContext($queryContainer, $request);
        $result = $this->executor->execute($queryContainer, $context);

        return $this->resultToResponse($result);
    }

    private function resultToResponse(ExecutionResult $result): Response
    {
        $content = json_encode($result, JSON_THROW_ON_ERROR);

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

        return new OperationParams($query, $operationName, $variables, $extensions, $readOnly);
    }
}
