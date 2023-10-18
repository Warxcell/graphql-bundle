<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Controller;

use GraphQL\Error\Error;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Promise\Promise;
use GraphQL\Server\OperationParams;
use GraphQL\Server\RequestError;
use GraphQL\Server\StandardServer;
use GraphQL\Utils\Utils;
use JsonException;
use LogicException;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function explode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function parse_str;
use function str_contains;

use const JSON_THROW_ON_ERROR;

final class GraphQL
{
    public function __construct(
        private readonly StandardServer $server
    ) {
    }

    /**
     * @throws RequestError
     * @throws JsonException
     */
    public function __invoke(
        Request $request,
        HttpFoundationFactoryInterface $psrToSymfony,
        PsrHttpFactory $symfonyToPsr,
    ): Response {
        try {
            $params = $this->parseRequest($request);
            $result = $this->server->executeRequest($params);
        } catch (Throwable $throwable) {
            $result = new ExecutionResult(null, [
                Error::createLocatedError($throwable),
            ]);
        }

        if ($result instanceof Promise || is_array($result)) {
            throw new LogicException('Promise not supported');
        }

        return $this->resultToResponse($result);
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
     * @throws JsonException
     */
    private function resultToResponse(ExecutionResult $result): Response
    {
        $response = new Response();
        $response->setContent(json_encode($result, JSON_THROW_ON_ERROR));
        $response->headers->set('Content-Type', 'application/json');

        return $response;
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
}
