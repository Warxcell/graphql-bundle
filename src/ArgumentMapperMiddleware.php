<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use GraphQL\Type\Definition\ResolveInfo;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function count;

final class ArgumentMapperMiddleware
{
    public function __construct(
        /**
         * @var array<string, array<string, class-string>>
         */
        private readonly array $argumentsMapping,
        private readonly ValidatorInterface $validator
    ) {
    }

    /**
     * @param array<array-key, mixed> $args
     */
    public function __invoke(
        mixed $parent,
        array $args,
        mixed $context,
        ResolveInfo $info,
        callable $next
    ): mixed {
        $class = $this->argumentsMapping[$info->parentType->name][$info->fieldName] ?? null;
        if ($class) {
            $validate = count($args) > 0;

            $args = new $class(...$args);

            if ($validate) { // validate is slow - we are stopping it here, because its empty object anyway - we optimized it :)
                $errors = $this->validator->validate($args);
                if (count($errors) > 0) {
                    throw new ConstraintViolationException($errors);
                }
            }
        }

        return $next($parent, $args, $context, $info);
    }
}
