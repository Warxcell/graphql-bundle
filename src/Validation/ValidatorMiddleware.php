<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Validation;

use Arxy\GraphQL\ConstraintViolationException;
use GraphQL\Type\Definition\ResolveInfo;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function count;

final class ValidatorMiddleware
{
    public function __construct(
        private readonly ValidatorInterface $validator
    ) {
    }

    public function __invoke(mixed $parent, mixed $args, mixed $context, ResolveInfo $info, callable $next): mixed
    {
        $errors = $this->validator->validate($args);
        if (count($errors) > 0) {
            throw new ConstraintViolationException($errors);
        }

        return $next($parent, $args, $context, $info);
    }
}
