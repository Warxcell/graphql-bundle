<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use Closure;

interface Plugin
{
    public function resolveRootValue(Closure $next): Closure;

    public function resolveContext(Closure $next): Closure;

    public function onResolverCalled(Closure $next): Closure;
}
