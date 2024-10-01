<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Controller;

use Arxy\GraphQL\QueryContainer;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Values;
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
        private CacheItemPoolInterface $cache,
        private Schema $schema
    ) {
    }

    private function shouldCache(QueryContainer $queryContainer): ?array
    {
        return Values::getDirectiveValues(
            $this->schema->getDirective('cacheQuery'),
            $queryContainer->operationDefinitionNode,
            $queryContainer->variables
        );
    }

    public function execute(QueryContainer $queryContainer, mixed $context): ExecutionResult
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
                $result = $this->executor->execute($queryContainer, $context);

                $cached->set($result);
                if ($cache['ttl']) {
                    $cached->expiresAfter($cache['ttl']);
                }

                $this->cache->save($cached);
            }

            return $cached->get();
        }

        return $this->executor->execute($queryContainer, $context);
    }
}
