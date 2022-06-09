<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Controller;

use Arxy\GraphQL\Plugin;
use Closure;
use GraphQL\Error\DebugFlag;
use GraphQL\Executor\Promise\Adapter\SyncPromiseAdapter;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Server\OperationParams;
use GraphQL\Server\ServerConfig;
use GraphQL\Server\StandardServer;
use GraphQL\Type\Schema;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function assert;
use function get_class;
use function iterator_to_array;
use function json_decode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

final class GraphQL
{
    private readonly Closure $handler;

    /**
     * @param Schema $schema
     * @param SyncPromiseAdapter $promiseAdapter
     * @param bool $debug
     * @param iterable<Plugin> $plugins
     */
    public function __construct(
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        Schema $schema,
        SyncPromiseAdapter $promiseAdapter,
        bool $debug,
        iterable $plugins,
        LoggerInterface $logger
    ) {
        $plugins = iterator_to_array($plugins);

        $resolveMiddleware = static function (array $plugins, int $offset, Closure $resolver, Closure $resolve) use (
            &$resolveMiddleware
        ): Closure {
            if (!isset($plugins[$offset])) {
                return $resolver;
            }
            $next = $resolveMiddleware($plugins, $offset + 1, $resolver, $resolve);

            $plugin = $plugins[$offset];

            assert($plugin instanceof Plugin);

            return $resolve($plugin, $next);
        };

        $resolveValue = $resolveMiddleware(
            $plugins, 0, static fn (
            mixed $value,
            OperationParams $params,
            DocumentNode $doc,
            string $operationType
        ): mixed => $value,
            static fn (Plugin $plugin, Closure $next) => $plugin->resolveRootValue($next)
        );

        $resolveContext = $resolveMiddleware(
            $plugins, 0, static fn (
            array $context,
            OperationParams $params,
            DocumentNode $doc,
            string $operationType
        ) => $context,
            static fn (Plugin $plugin, Closure $next) => $plugin->resolveContext($next)
        );

        $server = new StandardServer(
            ServerConfig::create()
                ->setRootValue(
                    static fn (
                        OperationParams $params,
                        DocumentNode $doc,
                        string $operationType
                    ): mixed => $resolveValue(null, $params, $doc, $operationType)
                )
                ->setContext(
                    static fn (
                        OperationParams $params,
                        DocumentNode $doc,
                        string $operationType
                    ): mixed => $resolveContext([], $params, $doc, $operationType)
                )
                ->setSchema($schema)
                ->setErrorsHandler(static function (array $errors, Closure $formatter) use ($logger): array {
                    $formatted = [];

                    foreach ($errors as $error) {
                        $message = sprintf(
                            '[GraphQL] %s: %s[%d] (caught throwable) at %s line %s.',
                            get_class($error),
                            $error->getMessage(),
                            $error->getCode(),
                            $error->getFile(),
                            $error->getLine()
                        );

                        $logger->log(LogLevel::ERROR, $message, ['exception' => $error]);

                        $formatted[] = $formatter($error);
                    }

                    return $formatted;
                })
                ->setDebugFlag($debug ? DebugFlag::INCLUDE_TRACE | DebugFlag::INCLUDE_DEBUG_MESSAGE : DebugFlag::NONE)
                ->setPromiseAdapter($promiseAdapter)
                ->setPersistentQueryLoader(static function (string $queryId, OperationParams $operationParams) {
                    return $operationParams->query;
                })
        );

        $this->handler = static function (ServerRequestInterface $request) use (
            $server,
            $responseFactory,
            $streamFactory
        ) {
            $helper = $server->getHelper();

            $parsedBody = $helper->parsePsrRequest($request);

            $result = $server->executeRequest($parsedBody);

            $response = $responseFactory->createResponse();
            $stream = $streamFactory->createStream();

            return $helper->toPsrResponse($result, $response, $stream);
        };
    }

    public function __invoke(
        Request $request,
        HttpFoundationFactoryInterface $psrToSymfony,
        PsrHttpFactory $symfonyToPsr,
    ): Response {
        $psrRequest = $symfonyToPsr->createRequest($request);

        if ($psrRequest->getHeader('Content-Type')[0] === 'application/json') {
            $psrRequest = $psrRequest->withParsedBody(
                json_decode($psrRequest->getBody()->getContents(), true, JSON_THROW_ON_ERROR)
            );
        }

        return $psrToSymfony->createResponse(($this->handler)($psrRequest));
    }
}
