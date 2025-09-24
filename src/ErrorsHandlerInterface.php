<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use GraphQL\Error\Error;
use GraphQL\Executor\ExecutionResult;

/**
 * @phpstan-import-type ErrorFormatter from ExecutionResult
 * @phpstan-import-type SerializableErrors from ExecutionResult
 */
interface ErrorsHandlerInterface
{
    /**
     * @param list<Error> $errors
     * @param ErrorFormatter $formatter
     * @return SerializableErrors
     */
    public function handleErrors(array $errors, callable $formatter): array;
}
