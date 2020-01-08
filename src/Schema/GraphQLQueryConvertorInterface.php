<?php
namespace PoP\GraphQLAPIQuery\Schema;

interface GraphQLQueryConvertorInterface
{
    public function convertFromGraphQLToFieldQuery(string $graphQLQuery): string;
}
