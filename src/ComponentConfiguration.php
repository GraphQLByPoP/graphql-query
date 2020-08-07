<?php

declare(strict_types=1);

namespace PoP\GraphQLAPIQuery;

use PoP\ComponentModel\ComponentConfiguration\EnvironmentValueHelpers;
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
        $defaultValue = false;
        $callback = [EnvironmentValueHelpers::class, 'toBool'];

        // Initialize property from the environment/hook
        self::maybeInitializeConfigurationValue(
            $envVariable,
            $selfProperty,
            $defaultValue,
            $callback
        );
        return $selfProperty;
    }
}
