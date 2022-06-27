<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use GraphQL\Error\ClientAware;

interface ExceptionInterface extends ClientAware
{
    public function getExtensions(): array;

    public function getCategory(): string;
}
