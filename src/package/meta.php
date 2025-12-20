<?php

namespace staticphp\package;

use staticphp\package;
use staticphp\step\CreatePackages;

/**
 * Metapackage that provides the base prefix name (e.g., php-zts85)
 * and depends on the cli package, making "apk add php-zts85" work.
 */
class meta implements package
{
    public function getName(): string
    {
        // Return just the prefix without -cli (e.g., "php-zts85")
        return CreatePackages::getPrefix();
    }

    public function getFpmConfig(): array
    {
        return [
            'depends' => [
                CreatePackages::getPrefix() . '-cli',
            ],
            'provides' => [],
            'replaces' => [],
            'conflicts' => [],
            'files' => [],
        ];
    }

    public function getFpmExtraArgs(): array
    {
        return [];
    }

    public function getDebuginfoFpmConfig(): array
    {
        return [];
    }

    public function getLicense(): string
    {
        return 'PHP-3.01';
    }
}
