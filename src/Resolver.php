<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Resolver
{
    public function __construct(
        public readonly ?string $name = null
    ) {
    }
}
