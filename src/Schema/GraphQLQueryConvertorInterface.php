<?php

declare(strict_types=1);

namespace GraphQLByPoP\GraphQLQuery\Schema;

interface GraphQLQueryConvertorInterface
{
    /**
     * Convert the GraphQL Query to PoP query in its requested form
     */
    public function convertFromGraphQLToFieldQuery(
        string $graphQLQuery,
        ?array $variables = [],
        bool $enableMultipleQueryExecution = false,
        ?string $operationName = null
    ): string;

    /**
     * Indicates if the variable must be dealt with as an expression: if its name starts with "_"
     *
     * @param string $variableName
     * @return boolean
     */
    public function treatVariableAsExpression(string $variableName): bool;
}
