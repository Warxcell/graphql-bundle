<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Server\OperationParams;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

interface ContextFactoryInterface
{
    public function createContext(
        OperationParams $params,
        DocumentNode $doc,
        string $operationType,
        ServerRequestInterface|null $request = null,
        ResponseInterface|null $response = null
    ): mixed;
}
