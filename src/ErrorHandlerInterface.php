<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use Closure;
use GraphQL\Executor\ExecutionResult;
use Throwable;

/**
 * @phpstan-import-type ErrorFormatter from ExecutionResult
 * @phpstan-import-type SerializableErrors from ExecutionResult
 */
interface ErrorHandlerInterface
{
    /**
     * @param list<Throwable> $errors
     * @param ErrorFormatter $formatter
     * @return SerializableErrors
     */
    public function handleErrors(array $errors, Closure $formatter): array;
}
