<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Cache;

use GraphQL\Executor\Values;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\FragmentSpreadNode;
use GraphQL\Language\AST\InlineFragmentNode;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Type\Definition\AbstractType;
use GraphQL\Type\Definition\HasFieldsType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Introspection;
use GraphQL\Type\Schema;

final class CacheKeyGenerator
{
    /**
     * @var \WeakMap<FieldNode, mixed>
     */
    private \WeakMap $cache;

    public function __construct()
    {
        $this->cache = new \WeakMap();
    }

    public function getKeys(ResolveInfo $info): array
    {
        /**
         * @var array<int, mixed>
         */
        $cacheKeys = [];

        foreach ($info->fieldNodes as $fieldNode) {
            if (!$this->cache->offsetExists($fieldNode)) {
                if ($fieldNode->selectionSet === null) {
                    continue;
                }

                $type = Type::getNamedType(
                    $info->parentType->getField($fieldNode->name->value)->getType()
                );
                assert($type instanceof Type, 'known because schema validation');

                $cacheKeys[$fieldNode->name->value] = $this->cache[$fieldNode] = $this->analyzeSelectionSet(
                    $fieldNode->selectionSet,
                    $type,
                    $info->schema,
                    $info->variableValues,
                    $info->fragments,
                );
            } else {
                $cacheKeys[$fieldNode->name->value] = $this->cache->offsetGet($fieldNode);
            }
        }

        return $cacheKeys;
    }

    /**
     * @param array<string, mixed> $variableValues
     * @return array<int, mixed>
     */
    private function analyzeSubFields(
        Type $type,
        SelectionSetNode $selectionSet,
        Schema $schema,
        array $variableValues,
        array $fragments,
    ): array {
        $type = Type::getNamedType($type);

        if ($type instanceof ObjectType || $type instanceof AbstractType) {
            return $this->analyzeSelectionSet(
                $selectionSet,
                $type,
                $schema,
                $variableValues,
                $fragments,
            );
        }

        return [];
    }

    /**
     * @param array<string, mixed> $variableValues
     * @return array<int, mixed>
     */
    private function analyzeSelectionSet(
        SelectionSetNode $selectionSet,
        Type $parentType,
        Schema $schema,
        array $variableValues,
        array $fragments,
    ): array {
        $cacheKeys = [];
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

                $args = Values::getArgumentValues($type, $selection, $variableValues);

                if ($args !== []) {
                    $cacheKeys[$fieldName]['args'] = $args;
                }

                $nestedSelectionSet = $selection->selectionSet;

                if (null !== $nestedSelectionSet) {
                    $cacheKeys[$fieldName]['fields'] = $this->analyzeSubFields(
                        $selectionType,
                        $nestedSelectionSet,
                        $schema,
                        $variableValues,
                        $fragments
                    );
                }
            } elseif ($selection instanceof FragmentSpreadNode) {
                $spreadName = $selection->name->value;
                $fragment = $fragments[$spreadName] ?? null;

                if ($fragment === null) {
                    continue;
                }

                $type = $schema->getType($fragment->typeCondition->name->value);
                assert($type instanceof Type, 'ensured by query validation');

                $cacheKeys = [
                    ...$cacheKeys,
                    ...$this->analyzeSubFields(
                        $type,
                        $fragment->selectionSet,
                        $schema,
                        $variableValues,
                        $fragments,
                    ),
                ];
            } elseif ($selection instanceof InlineFragmentNode) {
                $typeCondition = $selection->typeCondition;
                $type = $typeCondition === null
                    ? $parentType
                    : $schema->getType($typeCondition->name->value);
                assert($type instanceof Type, 'ensured by query validation');

                $cacheKeys = [
                    ...$cacheKeys,
                    ...$this->analyzeSubFields(
                        $type,
                        $selection->selectionSet,
                        $schema,
                        $variableValues,
                        $fragments,
                    ),
                ];
            }
        }

        return $cacheKeys;
    }
}
