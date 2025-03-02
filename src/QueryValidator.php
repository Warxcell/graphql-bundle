<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Type\Schema;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\QueryComplexity;

final readonly class QueryValidator
{
    public function __construct(
        private Schema $schema,
    ) {
    }

    /**
     * @param array<string, mixed>|null $variables
     * @throws QueryError
     */
    public function validate(DocumentNode $documentNode, ?array $variables): void
    {
        $queryComplexity = DocumentValidator::getRule(QueryComplexity::class);
        assert(
            $queryComplexity instanceof QueryComplexity,
            'should not register a different rule for QueryComplexity'
        );

        $queryComplexity->setRawVariableValues($variables);
        $validationErrors = DocumentValidator::validate($this->schema, $documentNode);

        if ($validationErrors !== []) {
            throw new QueryError($validationErrors);
        }
    }
}