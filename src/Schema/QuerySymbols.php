<?php

declare(strict_types=1);

namespace GraphQLByPoP\GraphQLQuery\Schema;

class QuerySymbols
{
    /**
     * Names for variables supporting the @export directive must start with this token
     */
    const VARIABLE_AS_EXPRESSION_NAME_PREFIX = '_';
    /**
     * Support resolving other fields from the same type in field/directive arguments:
     * Replace posts(searchfor: "{{title}}") with posts(searchfor: "sprintf(%s, [title()])")
     */
    const EMBEDDABLE_FIELD_PREFIX = '{{';
    const EMBEDDABLE_FIELD_SUFFIX = '}}';
}
