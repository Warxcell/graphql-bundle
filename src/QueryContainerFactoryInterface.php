<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

interface QueryContainerFactoryInterface
{
    /**
     * @throws QueryError
     */
    public function create(OperationParams $params): QueryContainer;
}
