<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Controller;

use Arxy\GraphQL\OperationParams;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Promise\Adapter\SyncPromiseAdapter;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\OperationDefinitionNode;
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
        private CacheItemPoolInterface $operationResultCache
    ) {
    }

    private function shouldCache(OperationDefinitionNode $operationDefinitionNode): bool
    {
        foreach ($operationDefinitionNode->directives as $directive) {
            if ($directive->name->value === 'cacheQuery') {
                return true;
            }
        }

        return false;
    }

    public function execute(
        Schema $schema,
        SyncPromiseAdapter $promiseAdapter,
        OperationParams $params,
        DocumentNode $documentNode,
        OperationDefinitionNode $operationDefinitionNode
    ): ExecutionResult {
        if ($this->shouldCache($operationDefinitionNode)) {
            $cached = $this->operationResultCache->getItem(
                md5(
                    sprintf(
                        'query-%s-params-%s-extensions-%s',
                        $params->queryCacheKey,
                        json_encode($params->variables, flags: JSON_THROW_ON_ERROR),
                        json_encode($params->extensions, flags: JSON_THROW_ON_ERROR)
                    )
                )
            );

            if (!$cached->isHit()) {
                $result = $this->executor->execute(
                    $schema,
                    $promiseAdapter,
                    $params,
                    $documentNode,
                    $operationDefinitionNode
                );

                $cached->set($result);
                $this->operationResultCache->save($cached);
            }

            return $cached->get();
        }

        return $this->executor->execute($schema, $promiseAdapter, $params, $documentNode, $operationDefinitionNode);
    }
}
