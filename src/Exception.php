<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

interface Exception
{
    public function getExtensions(): array;
}
