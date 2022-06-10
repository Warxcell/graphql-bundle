<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

final class Query implements Resolver
{
    public function ping(): string
    {
        return 'pong';
    }
}
