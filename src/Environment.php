<?php

declare(strict_types=1);

namespace PoP\GraphQLAPIQuery;

class Environment
{
    public const ENABLE_VARIABLES_AS_EXPRESSIONS = 'ENABLE_VARIABLES_AS_EXPRESSIONS';

    public static function enableVariablesAsExpressions(): bool
    {
        return isset($_ENV[self::ENABLE_VARIABLES_AS_EXPRESSIONS]) ? strtolower($_ENV[self::ENABLE_VARIABLES_AS_EXPRESSIONS]) == "true" : false;
    }
}
