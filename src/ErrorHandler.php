<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use Closure;
use GraphQL\Error\ClientAware;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

use function sprintf;

final class ErrorHandler implements ErrorHandlerInterface
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function handleErrors(array $errors, Closure $formatter): array
    {
        $formatted = [];
        foreach ($errors as $error) {
            $previous = $error->getPrevious();
            if (!$previous instanceof ClientAware || !$previous->isClientSafe()) {
                $message = sprintf(
                    '[GraphQL] %s: %s[%d] (caught throwable) at %s line %s.',
                    $error::class,
                    $error->getMessage(),
                    $error->getCode(),
                    $error->getFile(),
                    $error->getLine()
                );

                $this->logger->log(LogLevel::ERROR, $message, ['exception' => $error]);
            }

            $formatted[] = $formatter($error);
        }

        return $formatted;
    }
}
