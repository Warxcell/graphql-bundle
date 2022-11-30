<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use RuntimeException;

final class AuthorizationError extends RuntimeException implements ExceptionInterface
{
    public function __construct(
        $message = 'You are not authorized!'
    ) {
        parent::__construct($message);
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
        return 'authorization';
    }
}
