<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Controller;

use Arxy\GraphQL\RequestHandler;
use GraphQL\Server\RequestError;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class GraphQL
{
    public function __construct(
        private readonly RequestHandler $handler
    ) {
    }

    /**
     * @throws RequestError
     */
    public function __invoke(
        Request $request,
        HttpFoundationFactoryInterface $psrToSymfony,
        PsrHttpFactory $symfonyToPsr,
    ): Response {
        $psrRequest = $symfonyToPsr->createRequest($request);

        return $psrToSymfony->createResponse($this->handler->handle($psrRequest));
    }
}
