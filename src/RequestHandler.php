<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use GraphQL\Executor\Promise\Promise;
use GraphQL\Server\Helper;
use GraphQL\Server\RequestError;
use GraphQL\Server\StandardServer;
use LogicException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RequestHandler implements RequestHandlerInterface
{
    private readonly Helper $helper;

    public function __construct(
        private readonly StandardServer $server,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory
    ) {
        $this->helper = new Helper();
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

        $response = $this->helper->toPsrResponse($result, $response, $stream);;

        if ($response instanceof Promise) {
            throw new LogicException('Promise not supported');
        }

        return $response;
    }
}
