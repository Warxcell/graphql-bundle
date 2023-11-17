<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use GraphQL\Executor\ExecutionResult;
use Throwable;

/**
 * @phpstan-import-type ErrorFormatter from ExecutionResult
 * @phpstan-import-type SerializableErrors from ExecutionResult
 */
interface ErrorsHandlerInterface
{
    /**
     * @param list<Throwable> $errors
     * @param ErrorFormatter $formatter
     * @return SerializableErrors
     */
    public function handleErrors(array $errors, callable $formatter): array;
}
