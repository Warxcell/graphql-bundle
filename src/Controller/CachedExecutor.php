<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Controller;

use Arxy\GraphQL\OperationParams;
use Arxy\GraphQL\QueryContainer;
use Arxy\GraphQL\Util;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Promise\Adapter\SyncPromiseAdapter;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\IntValueNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Language\Parser;
use GraphQL\Language\Printer;
use GraphQL\Type\Schema;
use LogicException;
use Psr\Cache\CacheItemPoolInterface;

use function json_encode;
use function md5;
use function sprintf;

use const JSON_THROW_ON_ERROR;

final readonly class CachedExecutor implements ExecutorInterface
{
    public function __construct(
        private ExecutorInterface $executor,
        private CacheItemPoolInterface $cache,
        private Schema $schema
    ) {
    }

    private function shouldCache(QueryContainer $queryContainer): ?iterable
    {
        $directives = (array)Util::getDirectives(
            $queryContainer->operationDefinitionNode->directives,
            $this->schema,
            $queryContainer->variables
        );

        return $directives['cacheQuery'] ?? null;
    }

    public function execute(QueryContainer $queryContainer): ExecutionResult
    {
        $cache = $this->shouldCache($queryContainer);
        if ($cache) {
            $cached = $this->cache->getItem(
                md5(
                    sprintf(
                        'query-%s-variables-%s',
                        $queryContainer->cacheKey,
                        json_encode($queryContainer->variables, flags: JSON_THROW_ON_ERROR),
                    )
                )
            );

            if (!$cached->isHit()) {
                $cache = (array)$cache;
                $result = $this->executor->execute($queryContainer);

                $cached->set($result);
                if ($cache['ttl']) {
                    $cached->expiresAfter($cache['ttl']);
                }

                $this->cache->save($cached);
            }

            return $cached->get();
        }

        return $this->executor->execute($queryContainer);
    }
}
