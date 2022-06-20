<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use GraphQL\Language\AST\DocumentNode;

interface DocumentNodeProviderInterface
{
    public function getDocumentNode(): DocumentNode;
}
