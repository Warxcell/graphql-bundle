<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

interface ResolverMapInterface
{
    public const RESOLVE_TYPE = '__resolveType';
    public const PARSE_VALUE = 'parseValue';
    public const PARSE_LITERAL = 'parseLiteral';
    public const SERIALIZE = 'serialize';

    public function map(): array;
}
