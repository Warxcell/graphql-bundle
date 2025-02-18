<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Cache;

use Arxy\GraphQL\GraphQL\ResolveInfo;
use GraphQL\Executor\Values;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\FragmentSpreadNode;
use GraphQL\Language\AST\InlineFragmentNode;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Type\Definition\AbstractType;
use GraphQL\Type\Definition\HasFieldsType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Introspection;
use GraphQL\Type\Schema;

class CacheKeyGenerator
{
    public function getKey(ResolveInfo $info): string
    {
        /**
         * @var array<int, mixed>
         */
        $cacheKeys = [];

        foreach ($info->fieldNodes as $fieldNode) {
            if ($fieldNode->selectionSet === null) {
                continue;
            }

            $type = Type::getNamedType(
                $info->parentType->getField($fieldNode->name->value)->getType()
            );
            assert($type instanceof Type, 'known because schema validation');

            $cacheKeys[$fieldNode->name->value] = [];

            $this->analyzeSelectionSet(
                $fieldNode->selectionSet,
                $type,
                $info->schema,
                $info->rawVariableValues,
                $cacheKeys[$fieldNode->name->value]
            );
        }

        return md5(serialize($cacheKeys));
    }

    /**
     * @param array<string, mixed> $rawVariableValues
     * @param array<int, mixed> $cacheKeys
     */
    private function analyzeSubFields(
        Type $type,
        SelectionSetNode $selectionSet,
        Schema $schema,
        array $rawVariableValues,
        array &$cacheKeys
    ): void {
        $type = Type::getNamedType($type);

        if ($type instanceof ObjectType || $type instanceof AbstractType) {
            $cacheKeys['fields'] = [];
            $this->analyzeSelectionSet($selectionSet, $type, $schema, $rawVariableValues, $cacheKeys['fields']);
        }
    }

    /**
     * @param array<string, mixed> $rawVariableValues
     * @param array<int, mixed> $cacheKeys
     */
    private function analyzeSelectionSet(
        SelectionSetNode $selectionSet,
        Type $parentType,
        Schema $schema,
        array $rawVariableValues,
        array &$cacheKeys
    ): void {
        foreach ($selectionSet->selections as $selection) {
            if ($selection instanceof FieldNode) {
                $fieldName = $selection->name->value;

                if ($fieldName === Introspection::TYPE_NAME_FIELD_NAME) {
                    continue;
                }

                $cacheKeys[$fieldName] = [];

                assert($parentType instanceof HasFieldsType, 'ensured by query validation');

                $type = $parentType->getField($fieldName);
                $selectionType = $type->getType();

                $args = Values::getArgumentValues($type, $selection, $rawVariableValues);

                if ($args !== []) {
                    $cacheKeys[$fieldName]['args'] = $args;
                }

                $nestedSelectionSet = $selection->selectionSet;

                if (null !== $nestedSelectionSet) {
                    $this->analyzeSubFields(
                        $selectionType,
                        $nestedSelectionSet,
                        $schema,
                        $rawVariableValues,
                        $cacheKeys[$fieldName]
                    );
                }
            } elseif ($selection instanceof FragmentSpreadNode) {
                $spreadName = $selection->name->value;
                $fragment = $this->fragments[$spreadName] ?? null;

                if ($fragment === null) {
                    continue;
                }

                $type = $schema->getType($fragment->typeCondition->name->value);
                assert($type instanceof Type, 'ensured by query validation');

                $this->analyzeSubFields($type, $fragment->selectionSet, $schema, $rawVariableValues, $cacheKeys);
            } elseif ($selection instanceof InlineFragmentNode) {
                $typeCondition = $selection->typeCondition;
                $type = $typeCondition === null
                    ? $parentType
                    : $schema->getType($typeCondition->name->value);
                assert($type instanceof Type, 'ensured by query validation');

                $this->analyzeSubFields($type, $selection->selectionSet, $schema, $rawVariableValues, $cacheKeys);
            }
        }
    }
}
