<?php

declare(strict_types=1);

namespace PoP\GraphQLAPIQuery;

use PoP\GraphQLAPI\Component as GraphQLAPIComponent;
use PoP\Root\Component\AbstractComponent;
use PoP\Root\Component\CanDisableComponentTrait;
use PoP\Root\Component\YAMLServicesTrait;

/**
 * Initialize component
 */
class Component extends AbstractComponent
{
    use YAMLServicesTrait, CanDisableComponentTrait;
    
    // const VERSION = '0.1.0';

    public static function getDependedComponentClasses(): array
    {
        return [
            \PoP\Engine\Component::class,
            \PoP\GraphQLAPI\Component::class,
        ];
    }

    /**
     * Initialize services
     */
    public static function init()
    {
        if (self::isEnabled()) {
            parent::init();
            self::initYAMLServices(dirname(__DIR__));
        }
    }

    protected static function resolveEnabled()
    {
        return GraphQLAPIComponent::isEnabled();
    }
}
