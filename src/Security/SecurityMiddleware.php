<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Security;

use GraphQL\Type\Definition\ResolveInfo;

final class SecurityMiddleware
{
    public function __construct(
        private readonly string $role
    ) {
    }

    public function __invoke(
        mixed $parent,
        mixed $args,
        SecurityAwareContext $context,
        ResolveInfo $info,
        callable $next
    ): mixed {
        if (!$context->getSecurity()->isGranted($this->role)) {
            throw new AuthorizationError();
        }

        return $next($parent, $args, $context, $info);
    }
}
