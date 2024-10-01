<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Controller;

use Arxy\GraphQL\OperationParams;
use Arxy\GraphQL\QueryContainer;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Promise\Adapter\SyncPromiseAdapter;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Language\Printer;
use GraphQL\Type\Schema;
use Psr\Cache\CacheItemPoolInterface;

use function json_encode;
use function md5;
use function sprintf;

use const JSON_THROW_ON_ERROR;

final readonly class CachedExecutor implements ExecutorInterface
{
    public function __construct(
        private ExecutorInterface $executor,
        private CacheItemPoolInterface $cache
    ) {
    }

    private function shouldCache(QueryContainer $queryContainer): bool
    {
        foreach ($queryContainer->operationDefinitionNode->directives as $directive) {
            if ($directive->name->value === 'cacheQuery') {
                return true;
            }
        }

        return false;
    }

    public function execute(QueryContainer $queryContainer): ExecutionResult
    {
        if ($this->shouldCache($queryContainer)) {
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
                $result = $this->executor->execute($queryContainer);

                $cached->set($result);
                $this->cache->save($cached);
            }

            return $cached->get();
        }

        return $this->executor->execute($queryContainer);
    }
}
