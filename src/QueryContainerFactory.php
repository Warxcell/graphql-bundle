<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use GraphQL\Error\Error;
use GraphQL\Language\Parser;
use GraphQL\Server\RequestError;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\QueryComplexity;

use Psr\Cache\CacheItemPoolInterface;

use function assert;
use function md5;

final readonly class QueryContainerFactory implements QueryContainerFactoryInterface
{
    public function __construct(
        private Schema $schema,
        private CacheItemPoolInterface $queryCache,
    ) {
    }

    /**
     * @throws QueryError
     */
    public function create(OperationParams $params): QueryContainer
    {
        if (null === $params->query) {
            throw new QueryError([Error::createLocatedError(new RequestError('"query" must be string, but got null'))]);
        }
        $queryCacheKey = md5($params->query);
        $cacheItem = $this->queryCache->getItem($queryCacheKey);

        if ($cacheItem->isHit()) {
            $documentNode = AST::fromArray($cacheItem->get());
        } else {
            $documentNode = Parser::parse($params->query);

            $queryComplexity = DocumentValidator::getRule(QueryComplexity::class);
            assert(
                $queryComplexity instanceof QueryComplexity,
                'should not register a different rule for QueryComplexity'
            );

            $queryComplexity->setRawVariableValues($params->variables);
            $validationErrors = DocumentValidator::validate($this->schema, $documentNode);

            if ($validationErrors !== []) {
                throw new QueryError($validationErrors);
            }

            $cacheItem->set(AST::toArray($documentNode));
            $this->queryCache->save($cacheItem);
        }

        $operationDefinitionNode = AST::getOperationAST($documentNode, $params->operationName);

        if ($operationDefinitionNode === null) {
            throw new QueryError([Error::createLocatedError(new RequestError('Failed to determine operation type'))]);
        }

        return new QueryContainer(
            query: $params->query,
            cacheKey: $queryCacheKey,
            documentNode: $documentNode,
            operationDefinitionNode: $operationDefinitionNode,
            variables: $params->variables,
        );
    }
}
