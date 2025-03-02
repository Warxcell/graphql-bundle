<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use GraphQL\Error\Error;
use GraphQL\Language\Parser;
use GraphQL\Language\Printer;
use GraphQL\Server\RequestError;

use GraphQL\Utils\AST;
use Psr\Cache\CacheItemPoolInterface;

final readonly class PersistedQueryContainerFactory implements QueryContainerFactoryInterface
{
    public function __construct(
        private QueryContainerFactoryInterface $decorated,
        private CacheItemPoolInterface $cache,
        private QueryValidator $queryValidator
    ) {
    }

    public function create(OperationParams $params): QueryContainer
    {
        if (isset($params->extensions['persistedQuery']['sha256Hash'])) {
            $hash = $params->extensions['persistedQuery']['sha256Hash'];

            $cacheItem = $this->cache->getItem($hash);

            if (!$cacheItem->isHit()) {
                if ($params->query === null) {
                    throw new QueryError([Error::createLocatedError(new RequestError('PersistedQueryNotFound'))]);
                }
                $query = $params->query;

                $documentNode = Parser::parse($params->query);

                $this->queryValidator->validate($documentNode, $params->variables);

                $cacheItem->set(AST::toArray($documentNode));
                $this->cache->save($cacheItem);
            } else {
                $documentNode = AST::fromArray($cacheItem->get());
                $query = Printer::doPrint($documentNode);
            }

            $operationDefinitionNode = AST::getOperationAST($documentNode, $params->operationName);

            if ($operationDefinitionNode === null) {
                throw new QueryError([Error::createLocatedError(new RequestError('Failed to determine operation type'))]
                );
            }

            return new QueryContainer(
                query: $query,
                cacheKey: $hash,
                documentNode: $documentNode,
                operationDefinitionNode: $operationDefinitionNode,
                variables: $params->variables,
            );
        }

        return $this->decorated->create($params);
    }
}
