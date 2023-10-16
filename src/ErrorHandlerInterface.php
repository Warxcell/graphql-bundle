<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use Closure;
use Throwable;

interface ErrorHandlerInterface
{
    /**
     * @param list<Throwable> $errors
     * @param Closure(Throwable): string[] $formatter
     */
    public function handleErrors(array $errors, Closure $formatter): array;
}
