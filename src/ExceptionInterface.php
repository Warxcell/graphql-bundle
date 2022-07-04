<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use GraphQL\Error\ClientAware;
use GraphQL\Error\ProvidesExtensions;

interface ExceptionInterface extends ClientAware, ProvidesExtensions
{
    public function getCategory(): string;
}
