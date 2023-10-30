<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Security;

use Symfony\Component\Security\Core\Security;

interface SecurityAwareContext
{
    public function getSecurity(): Security;
}
