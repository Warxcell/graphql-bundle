<?php declare(strict_types=1);

namespace GraphQL\Type\Definition;

use GraphQL\Error\Error;
use GraphQL\Error\SerializationError;
use GraphQL\Language\AST\IntValueNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\Printer;
use GraphQL\Utils\Utils;

class IntType extends ScalarType
{
    // As per the GraphQL Spec, Integers are only treated as valid when a valid
    // 32-bit signed integer, providing the broadest support across platforms.
    //
    // n.b. JavaScript's integers are safe between -(2^53 - 1) and 2^53 - 1 because
    // they are internally represented as IEEE 754 doubles.
    public const MAX_INT = 2147483647;
    public const MIN_INT = -2147483648;

    public string $name = Type::INT;

    public ?string $description
        = 'The `Int` scalar type represents non-fractional signed whole numeric
values. Int can represent values between -(2^31) and 2^31 - 1. ';

    public function serialize($value): int
    {
        return $value;
    }

    /** @throws Error */
    public function parseValue($value): int
    {
        $isInt = \is_int($value)
            || (\is_float($value) && \floor($value) === $value);

        if (! $isInt) {
            $notInt = Utils::printSafeJson($value);
            throw new Error("Int cannot represent non-integer value: {$notInt}");
        }

        if ($value > self::MAX_INT || $value < self::MIN_INT) {
            $outOfRangeInt = Utils::printSafeJson($value);
            throw new Error("Int cannot represent non 32-bit signed integer value: {$outOfRangeInt}");
        }

        return (int) $value;
    }

    public function parseLiteral(Node $valueNode, ?array $variables = null): int
    {
        if ($valueNode instanceof IntValueNode) {
            $val = (int) $valueNode->value;
            if ($valueNode->value === (string) $val && $val >= self::MIN_INT && $val <= self::MAX_INT) {
                return $val;
            }
        }

        $notInt = Printer::doPrint($valueNode);
        throw new Error("Int cannot represent non-integer value: {$notInt}", $valueNode);
    }
}
