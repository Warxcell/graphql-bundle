<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use GraphQL\Error\ClientAware;
use RuntimeException;
use Symfony\Component\Validator\ConstraintViolationListInterface;

use function explode;

final class ConstraintViolationException extends RuntimeException implements Exception, ClientAware
{
    public function __construct(
        public readonly ConstraintViolationListInterface $constraintViolationList
    ) {
        parent::__construct('Constraint violation');
    }

    private function getViolationExtension(): iterable
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
                'message' => $violation->getMessage(),
            ];
        }

        return $formatted;
    }

    public function getExtensions(): array
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
}
