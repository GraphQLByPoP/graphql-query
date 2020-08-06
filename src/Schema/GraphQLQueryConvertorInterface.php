<?php

declare(strict_types=1);

namespace PoP\GraphQLAPIQuery\Schema;

interface GraphQLQueryConvertorInterface
{
    /**
     * Convert the GraphQL Query to PoP query.
     * Return a set with both the requested and the executable field query.
     *
     * For instance, when doing query batching, fields may be prepended
     * with "self" to have the queries be executed in stric order
     */
    public function convertFromGraphQLToFieldQuerySet(
        string $graphQLQuery,
        ?array $variables = []
    ): array;

    /**
     * Convert the GraphQL Query to PoP query in its requested form
     */
    public function convertFromGraphQLToRequestedFieldQuery(
        string $graphQLQuery,
        ?array $variables = []
    ): string;

    /**
     * Convert the GraphQL Query to PoP query in its executable form.
     *
     * For instance, when doing query batching, fields may be prepended
     * with "self" to have the queries be executed in stric order
     */
    public function convertFromGraphQLToExecutableFieldQuery(
        string $graphQLQuery,
        ?array $variables = []
    ): string;
    /**
     * Indicates if the variable must be dealt with as an expression: if its name starts with "_"
     *
     * @param string $variableName
     * @return boolean
     */
    public function treatVariableAsExpression(string $variableName): bool;
}
