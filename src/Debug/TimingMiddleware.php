<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Debug;

use GraphQL\Type\Definition\ResolveInfo;
use Symfony\Component\Stopwatch\Stopwatch;

use function sprintf;

final class TimingMiddleware
{
    public function __construct(
        private readonly Stopwatch $stopwatch
    ) {
    }

    public function __invoke(mixed $parent, mixed $args, mixed $context, ResolveInfo $info, callable $next): mixed
    {
        $stopwatch = $this->stopwatch->start(sprintf('Calling %s.%s', $info->parentType->name, $info->fieldName), 'GraphQL');
        try {
            return $next($parent, $args, $context, $info);
        } finally {
            $stopwatch->stop();
        }
    }
}
