<?php

namespace staticphp\package;

use staticphp\package;
use staticphp\step\CreatePackages;

class cgi implements package
{
    public function getName(): string
    {
        return CreatePackages::getPrefix() . '-cgi';
    }

    public function getFpmConfig(): array
    {
        $versionedConflicts = CreatePackages::getVersionedConflicts('-cgi');
        return [
            'depends' => [
                CreatePackages::getPrefix() . '-cli',
            ],
            'provides' => [
                'php-zts-cgi',
            ],
            'replaces' => $versionedConflicts,
            'conflicts' => $versionedConflicts,
            'files' => [
                BUILD_BIN_PATH . '/php-cgi' => '/usr/bin/php-cgi' . getBinarySuffix(),
            ]
        ];
    }

    public function getFpmExtraArgs(): array
    {
        return [];
    }

    public function getDebuginfoFpmConfig(): array
    {
        $src = BUILD_ROOT_PATH . '/debug/php-cgi-zts.debug';
        if (!file_exists($src)) {
            return [];
        }
        return [
            'depends' => [CreatePackages::getPrefix() . '-cgi'],
            'files' => [
                $src => '/usr/lib/debug/usr/bin/php-cgi' . getBinarySuffix() . '.debug',
            ],
        ];
    }

    public function getLicense(): string
    {
        return 'PHP-3.01';
    }
}
