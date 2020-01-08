<?php
namespace PoP\GraphQLAPIQuery\Facades;

use PoP\GraphQLAPIQuery\Schema\GraphQLQueryConvertorInterface;
use PoP\Root\Container\ContainerBuilderFactory;

class GraphQLQueryConvertorFacade
{
    public static function getInstance(): GraphQLQueryConvertorInterface
    {
        return ContainerBuilderFactory::getInstance()->get('graphql_query_convertor');
    }
}
