<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

final class Mutation
{
    public function ping(): string
    {
        return 'pong';
    }
}
