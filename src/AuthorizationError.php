<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use RuntimeException;

final class AuthorizationError extends RuntimeException implements Exception
{
    public function __construct(
        public readonly string $role
    ) {
        parent::__construct('You are not authorized!');
    }

    public function isClientSafe(): bool
    {
        return true;
    }

    public function getExtensions(): array
    {
        return [];
    }

    public function getCategory(): string
    {
        return 'user';
    }
}
