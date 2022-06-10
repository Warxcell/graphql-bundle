<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Bridge\Security;

use Arxy\GraphQL\AbstractPlugin;
use Arxy\GraphQL\DirectiveHelper;
use Closure;
use GraphQL\Error\UserError;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Server\OperationParams;
use GraphQL\Type\Definition\ResolveInfo;
use Symfony\Component\Security\Core\Security;

final class SecurityPlugin extends AbstractPlugin
{
    public function __construct(
        private readonly Security $security
    ) {
    }

    public function resolveContext(Closure $next): Closure
    {
        return function (
            mixed $context,
            OperationParams $params,
            DocumentNode $doc,
            string $operationType
        ) use ($next) {
            $token = $this->security->getToken();
            $context['authToken'] = $token;

            return $next($context, $params, $doc, $operationType);
        };
    }

    public function denyUnlessGranted(string $role)
    {
        if (!$this->security->isGranted($role)) {
            throw new UserError('Access denied');
        }
    }

    public function onResolverCalled(Closure $next): Closure
    {
        return function (mixed $parent, mixed $args, mixed $context, ResolveInfo $info) use ($next) {
            $isGrantedDirective = DirectiveHelper::getDirectiveValues('isGranted', $info);

            if ($isGrantedDirective) {
                $this->denyUnlessGranted($isGrantedDirective['role']);
            }

            return $next($parent, $args, $context, $info);
        };
    }
}
