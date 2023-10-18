<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use RuntimeException;
use Symfony\Component\Validator\ConstraintViolationListInterface;

use function explode;
use function preg_match;

final class ConstraintViolationException extends RuntimeException implements ExceptionInterface
{
    public function __construct(
        public readonly ConstraintViolationListInterface $constraintViolationList
    ) {
        parent::__construct('Constraint violation');
    }

    public function getExtensions(): ?array
    {
        return ['violations' => $this->getViolationExtension()];
    }

    public function isClientSafe(): bool
    {
        return true;
    }

    public function getCategory(): string
    {
        return 'validation';
    }

    /**
     * @return array{path: (string|int)[], message: string, code: string|null}[]
     */
    private function getViolationExtension(): array
    {
        $formatted = [];
        foreach ($this->constraintViolationList as $violation) {
            $path = [];

            if ($violation->getPropertyPath() !== '') {
                $exploded = explode('.', $violation->getPropertyPath());

                foreach ($exploded as $prop) {
                    if (preg_match('/(?<prop>\w+)\[(?<arrayKey>\d)+]/', $prop, $matches)) {
                        $path[] = $matches['prop'];
                        $path[] = (int)$matches['arrayKey'];
                    } else {
                        $path[] = $prop;
                    }
                }
            }

            $formatted[] = [
                'path' => $path,
                'message' => (string)$violation->getMessage(),
                'code' => $violation->getCode(),
            ];
        }

        return $formatted;
    }
}
