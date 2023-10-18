<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use GraphQL\Error\DebugFlag;
use GraphQL\Executor\Promise\PromiseAdapter;
use GraphQL\Server\ServerConfig;
use GraphQL\Server\StandardServer;
use GraphQL\Type\Schema;
use Throwable;

final class StandardServerFactory
{
    public static function factory(
        Schema $schema,
        ErrorHandlerInterface $errorsHandler,
        bool $debug,
        PromiseAdapter $promiseAdapter,
        ?ContextFactoryInterface $contextFactory,
    ): StandardServer {
        return new StandardServer(
            ServerConfig::create()
                ->setRootValue(
                    static fn(): mixed => null
                )
                ->setContext($contextFactory ? [$contextFactory, 'createContext'] : null)
                ->setSchema($schema)
                ->setErrorsHandler(static function (
                    array $errors,
                    callable $formatter
                ) use ($errorsHandler): array {
                    return $errorsHandler->handleErrors($errors, static function (Throwable $error) use (
                        $formatter
                    ): array {
                        $formatted = $formatter($error);

                        $previous = $error->getPrevious();
                        if ($previous instanceof ExceptionInterface) {
                            $formatted['extensions'] = [
                                ...($formatted['extensions'] ?? []),
                                'category' => $previous->getCategory(),
                            ];
                        }

                        return $formatted;
                    });
                })
                ->setDebugFlag($debug ? DebugFlag::INCLUDE_TRACE | DebugFlag::INCLUDE_DEBUG_MESSAGE : DebugFlag::NONE)
                ->setPromiseAdapter($promiseAdapter)
        );
    }
}
