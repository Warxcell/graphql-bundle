<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use GraphQL\Error\ClientAware;

interface Exception extends ClientAware
{
    public function getExtensions(): array;

    public function getCategory(): string;
}
