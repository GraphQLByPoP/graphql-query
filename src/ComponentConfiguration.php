<?php

declare(strict_types=1);

namespace PoP\GraphQLAPIQuery;

use PoP\ComponentModel\ComponentConfiguration\ComponentConfigurationTrait;

class ComponentConfiguration
{
    use ComponentConfigurationTrait;

    private static $enableVariablesAsExpressions;

    public static function enableVariablesAsExpressions(): bool
    {
        // Define properties
        $envVariable = Environment::ENABLE_VARIABLES_AS_EXPRESSIONS;
        $selfProperty = &self::$enableVariablesAsExpressions;
        $callback = [Environment::class, 'enableVariablesAsExpressions'];

        // Initialize property from the environment/hook
        self::maybeInitializeConfigurationValue(
            $envVariable,
            $selfProperty,
            $callback
        );
        return $selfProperty;
    }
}
