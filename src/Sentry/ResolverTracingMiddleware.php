<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Sentry;

use Arxy\GraphQL\Security\SecurityAwareContext;
use GraphQL\Type\Definition\ResolveInfo;
use Sentry\Tracing\SpanContext;

use function Sentry\trace;
use function sprintf;

class ResolverTracingMiddleware
{
    public function __invoke(
        mixed $parent,
        mixed $args,
        SecurityAwareContext $context,
        ResolveInfo $info,
        callable $next
    ): mixed {
        $spanContext = SpanContext::make()
            ->setOp('graphql.resolver')
            ->setDescription(sprintf('%s.%s', $info->parentType->name, $info->fieldName));

        return trace(static fn(): mixed => $next($parent, $args, $context, $info), $spanContext);
    }
}
