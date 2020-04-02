<?php
namespace PoP\GraphQLAPIQuery\Schema;

interface GraphQLQueryConvertorInterface
{
    public function convertFromGraphQLToFieldQuery(string $graphQLQuery, ?array $variables = []): string;
    /**
     * Indicates if the variable must be dealt with as an expression: if its name starts with "_"
     *
     * @param string $variableName
     * @return boolean
     */
    public function treatVariableAsExpression(string $variableName): bool;
}
