<?php

declare(strict_types=1);

namespace PoP\GraphQLAPIQuery\Schema;

interface GraphQLQueryConvertorInterface
{
    /**
     * Convert the GraphQL Query to PoP query in its requested form
     */
    public function convertFromGraphQLToFieldQuery(
        string $graphQLQuery,
        ?array $variables = [],
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
