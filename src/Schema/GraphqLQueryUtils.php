<?php

declare(strict_types=1);

namespace PoP\GraphQLAPIQuery\Schema;

use PoP\GraphQLAPIQuery\Schema\Tokens;

/**
 * @deprecated Not used anymore
 */
class GraphQLQueryUtils
{
    public static function convertLocationArrayIntoString(int $line, int $column): string
    {
        return sprintf(
            '%s%s%s',
            $line,
            Tokens::LOCATION_ITEMS_SEPARATOR,
            $column
        );
    }

    public static function convertLocationStringIntoArray(string $location): array
    {
        $locationParts = explode(Tokens::LOCATION_ITEMS_SEPARATOR, $location);
        return [
            'line' => $locationParts[0],
            'column' => $locationParts[1],
        ];
    }
}
