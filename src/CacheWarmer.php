<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use GraphQL\Error\Error;
use GraphQL\Error\SyntaxError;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

final class CacheWarmer implements CacheWarmerInterface
{
    public function __construct(
        private readonly SchemaBuilder $schemaBuilder
    ) {
    }

    public function isOptional(): bool
    {
        return false;
    }

    /**
     * @throws SyntaxError
     * @throws Error
     */
    public function warmUp(string $cacheDir)
    {
        $schema = $this->schemaBuilder->makeSchema();
        $schema->assertValid();
    }
}
