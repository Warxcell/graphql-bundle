<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Security;

use GraphQL\Type\Definition\ResolveInfo;

final class SecurityMiddleware
{
    public function __construct(
        /**
         * @var array<string, array<string, string>>
         */
        private readonly array $roles
    ) {
    }

    public function __invoke(
        mixed $parent,
        mixed $args,
        SecurityAwareContext $context,
        ResolveInfo $info,
        callable $next
    ): mixed {
        $role = $this->roles[$info->parentType->name][$info->fieldName] ?? null;

        if ($role && !$context->getSecurity()->isGranted($role)) {
            throw new AuthorizationError();
        }

        return $next($parent, $args, $context, $info);
    }
}
