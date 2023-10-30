<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use GraphQL\Type\Definition\ResolveInfo;

final class ArgumentMapperMiddleware
{
    public function __construct(
        /**
         * @var array<string, array<string, class-string>>
         */
        private readonly array $argumentsMapping,
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
            $args = new $class(...$args);
        }

        return $next($parent, $args, $context, $info);
    }
}
