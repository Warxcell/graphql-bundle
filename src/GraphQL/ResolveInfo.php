<?php

declare(strict_types=1);

namespace Arxy\GraphQL\GraphQL;

use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Schema;

class ResolveInfo extends \GraphQL\Type\Definition\ResolveInfo
{
    public function __construct(
        FieldDefinition $fieldDefinition,
        \ArrayObject $fieldNodes,
        ObjectType $parentType,
        array $path,
        Schema $schema,
        array $fragments,
        $rootValue,
        OperationDefinitionNode $operation,
        array $variableValues,
        public array $rawVariableValues = [],
        array $unaliasedPath = []
    ) {
        parent::__construct(
            $fieldDefinition,
            $fieldNodes,
            $parentType,
            $path,
            $schema,
            $fragments,
            $rootValue,
            $operation,
            $variableValues,
            $unaliasedPath
        );
        $this->variableValues = $variableValues;
    }
}
