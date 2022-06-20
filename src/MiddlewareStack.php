<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use Closure;

final class MiddlewareStack
{
    public static function wrap(callable $next, callable $original): Closure
    {
        return static fn (
            mixed $parent,
            mixed $args,
            mixed $context,
            mixed $info
        ) => $next($parent, $args, $context, $info, $original);
    }
}
