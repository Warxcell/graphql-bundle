<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use GraphQL\Error\ClientAware;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

use function sprintf;

final class ErrorHandler implements ErrorHandlerInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $logLevel = LogLevel::ERROR
    ) {
    }

    public function handleErrors(array $errors, callable $formatter): array
    {
        $formatted = [];
        foreach ($errors as $error) {
            if (!($error instanceof ClientAware && $error->isClientSafe())) {
                $message = sprintf(
                    '[GraphQL] "%s": "%s"[%d] at "%s" line "%s".',
                    $error::class,
                    $error->getMessage(),
                    $error->getCode(),
                    $error->getFile(),
                    $error->getLine()
                );

                $this->logger->log($this->logLevel, $message, ['exception' => $error]);
            }

            $formatted[] = $formatter($error);
        }

        return $formatted;
    }
}
