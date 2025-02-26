<?php
declare(strict_types=1);

namespace Arxy\GraphQL\Cache;

final readonly class CacheConfig
{
    public function __construct(
        public string $cacheKey,
        public ?int $ttl = null,
    ) {
    }
}