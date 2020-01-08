<?php
namespace PoP\GraphQLAPIQuery\Schema;

class GraphQLQueryConvertor implements GraphQLQueryConvertorInterface
{
    public function convertFromGraphQLToFieldQuery(string $graphQLQuery): string
    {
        // TODO
        $fieldQuery = $graphQLQuery;
        return $fieldQuery;
    }
}
