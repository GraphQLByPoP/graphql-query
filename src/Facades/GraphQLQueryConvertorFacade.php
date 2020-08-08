<?php

declare(strict_types=1);

namespace GraphQLByPoP\GraphQLQuery\Facades;

use GraphQLByPoP\GraphQLQuery\Schema\GraphQLQueryConvertorInterface;
use PoP\Root\Container\ContainerBuilderFactory;

class GraphQLQueryConvertorFacade
{
    public static function getInstance(): GraphQLQueryConvertorInterface
    {
        return ContainerBuilderFactory::getInstance()->get('graphql_query_convertor');
    }
}
