<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use GraphQL\Language\AST\FieldNode;
use GraphQL\Type\Definition\FieldDefinition;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function count;

/**
 * @template C of object
 */
final class ArgumentMapper
{
    public function __construct(
        /**
         * @var class-string<C>
         */
        private readonly string $class,
        private readonly ValidatorInterface $validator
    ) {
    }

    /**
     * @param array<array-key, mixed> $args
     * @return C
     */
    public function __invoke(
        array $args,
        FieldDefinition $fieldDefinition,
        FieldNode $fieldNode
    ): mixed {
        $validate = count($args) > 0;

        $args = new ($this->class)(...$args);

        if ($validate) { // validate is slow - we are stopping it here, because its empty object anyway - we optimized it :)
            $errors = $this->validator->validate($args);
            if (count($errors) > 0) {
                throw new ConstraintViolationException($errors);
            }
        }

        return $args;
    }
}
