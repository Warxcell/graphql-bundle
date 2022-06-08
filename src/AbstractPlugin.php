<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use Closure;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Server\OperationParams;
use GraphQL\Type\Definition\ResolveInfo;

abstract class AbstractPlugin implements Plugin
{
    public function resolveRootValue(Closure $next): Closure
    {
        return static fn (
            $value,
            OperationParams $params,
            DocumentNode $doc,
            string $operationType
        ): mixed => $next($value, $params, $doc, $operationType);
    }

    public function resolveContext(Closure $next): Closure
    {
        return static fn (
            array $context,
            OperationParams $params,
            DocumentNode $doc,
            string $operationType
        ): array => $next($context, $params, $doc, $operationType);
    }

    public function onResolverCalled(Closure $next): Closure
    {
        return static fn ($parent, array $args, $context, ResolveInfo $info) => $next($parent, $args, $context, $info);
    }

}
