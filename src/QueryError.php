<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use Exception;
use GraphQL\Error\Error;
use Throwable;

final class QueryError extends Exception
{
    public function __construct(
        /**
         * @var array<int, Error>
         */
        public readonly array $errors,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Failed to create query', previous: $previous);
    }
}
