<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

interface ExtensionsAwareContext
{
    /**
     * @return array<string, mixed>
     */
    public function getExtensions(): array;
}
