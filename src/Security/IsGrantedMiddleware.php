<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Security;

use Arxy\GraphQL\AuthorizationError;
use Arxy\GraphQL\DirectiveHelper;
use GraphQL\Type\Definition\ResolveInfo;
use Symfony\Component\Security\Core\Security;

final class IsGrantedMiddleware
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function __invoke(mixed $parent, mixed $args, mixed $context, ResolveInfo $info, callable $next): mixed
    {
        $isGrantedDirective = DirectiveHelper::getDirectiveValues('isGranted', $info);

        if ($isGrantedDirective && !$this->security->isGranted($isGrantedDirective['role'])) {
            throw new AuthorizationError($isGrantedDirective['role']);
        }

        return $next($parent, $args, $context, $info);
    }
}
