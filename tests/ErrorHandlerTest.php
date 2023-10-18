<?php

namespace Arxy\GraphQL\Tests;

use Arxy\GraphQL\ErrorHandler;
use Exception;
use GraphQL\Error\ClientAware;
use GraphQL\Error\DebugFlag;
use GraphQL\Error\Error;
use GraphQL\Error\FormattedError;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

use function array_filter;
use function array_map;

final class ErrorHandlerTest extends TestCase
{
    /**
     * @return iterable<int, array{array{Throwable, bool}[]}>
     */
    public static function handleErrorsDataProvider(): iterable
    {
        $logicException = new LogicException('message', 523);
        yield [
            [[$logicException, true]],
        ];

        $error = new Error('Test');
        yield [
            [[$error, false]],
        ];

        $errorWithThrowable = new Error('Test', previous: $logicException);
        yield [
            [[$errorWithThrowable, true]],
        ];

        $errorNotClientSafe = new Error(
            'Test', previous: new class extends Exception implements ClientAware {
            public function isClientSafe(): bool
            {
                return false;
            }
        }
        );
        yield [
            [[$errorNotClientSafe, true]],
        ];


        $errorWithClientSafe = new Error(
            'Test', previous: new class extends Exception implements ClientAware {
            public function isClientSafe(): bool
            {
                return true;
            }
        }
        );
        yield [
            [[$errorWithClientSafe, false]],
        ];


        $errorClientSafe = new class extends Exception implements ClientAware {
            public function isClientSafe(): bool
            {
                return true;
            }
        };
        yield [
            [[$errorClientSafe, false]],
        ];

        $errorNotClientSafe = new class extends Exception implements ClientAware {
            public function isClientSafe(): bool
            {
                return false;
            }
        };
        yield [
            [[$errorNotClientSafe, true]],
        ];
    }

    /**
     * @dataProvider handleErrorsDataProvider
     * @param array{Throwable, bool}[] $data
     */
    public function testHandleErrors(array $data): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        $logLevel = LogLevel::CRITICAL;

        $expectedThrowables = array_filter($data, static fn(array $throwable): bool => $throwable[1]);
        if (count($expectedThrowables) > 0) {
            foreach ($expectedThrowables as $expectedThrowable) {
                $logger->expects(self::once())
                    ->method('log')
                    ->with(
                        $logLevel,
                        sprintf(
                            '[GraphQL] "%s": "%s"[%d] at "%s" line "%s".',
                            $expectedThrowable[0]::class,
                            $expectedThrowable[0]->getMessage(),
                            $expectedThrowable[0]->getCode(),
                            $expectedThrowable[0]->getFile(),
                            $expectedThrowable[0]->getLine()
                        ),
                        ['exception' => $expectedThrowable[0]]
                    );
            }
        } else {
            $logger->expects(self::never())->method('log');
        }

        $errorHandler = new ErrorHandler($logger, $logLevel);

        $formatter = FormattedError::prepareFormatter(null, DebugFlag::RETHROW_INTERNAL_EXCEPTIONS);

        $throwables = array_map(static fn(array $throwable): Throwable => $throwable[0], $data);
        $errorHandler->handleErrors($throwables, $formatter);
    }
}
