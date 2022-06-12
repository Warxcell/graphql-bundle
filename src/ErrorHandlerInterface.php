<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use Closure;
use Throwable;

interface ErrorHandlerInterface
{
    /**
     * @param list<Throwable> $errors
     */
    public function handleErrors(array $errors, Closure $formatter): array;
}
