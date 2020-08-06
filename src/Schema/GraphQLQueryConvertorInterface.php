<?php

declare(strict_types=1);

namespace PoP\GraphQLAPIQuery\Schema;

interface GraphQLQueryConvertorInterface
{
    public function convertFromGraphQLToFieldQuery(string $graphQLQuery, ?array $variables = []): string;
    public function convertFromGraphQLToFieldQueries(string $graphQLQuery, ?array $variables = []): array;
    /**
     * Indicates if the variable must be dealt with as an expression: if its name starts with "_"
     *
     * @param string $variableName
     * @return boolean
     */
    public function treatVariableAsExpression(string $variableName): bool;
}
