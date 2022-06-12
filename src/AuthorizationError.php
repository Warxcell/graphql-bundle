<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use GraphQL\Error\ClientAware;
use RuntimeException;

final class AuthorizationError extends RuntimeException implements ClientAware
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

    public function getCategory(): string
    {
        return 'user';
    }
}
