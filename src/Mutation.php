<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

final class Mutation implements Resolver
{
    public function ping(): string
    {
        return 'pong';
    }
}
