<?php

namespace staticphp\package;

use staticphp\package;
use staticphp\step\CreatePackages;

class embed implements package
{
    public function getName(): string
    {
        return CreatePackages::getPrefix() . '-embed';
    }

    public function getFpmConfig(): array
    {
        $phpVersion = str_replace('.', '', SPP_PHP_VERSION);
        $name = 'libphp-zts-' . $phpVersion . '.so';
        $versionedConflicts = CreatePackages::getVersionedConflicts('-embed');
        return [
            'depends' => [
                CreatePackages::getPrefix() . '-cli',
            ],
            'provides' => [
                $name,
                'php-zts-embed',
                CreatePackages::getPrefix() . '-embed',
                'php-zts-embedded',
                CreatePackages::getPrefix() . '-embedded'
            ],
            'replaces' => $versionedConflicts,
            'conflicts' => $versionedConflicts,
            'files' => [
                BUILD_LIB_PATH . '/' . $name => getLibdir() . '/' . $name,
            ]
        ];
    }

    public function getFpmExtraArgs(): array
    {
        return [];
    }

    public function getDebuginfoFpmConfig(): array
    {
        $phpVersionDigits = str_replace('.', '', SPP_PHP_VERSION);
        $libName = 'libphp-zts-' . $phpVersionDigits . '.so';
        $src = BUILD_ROOT_PATH . '/debug/' . $libName . '.debug';
        if (!file_exists($src)) {
            return [];
        }
        $target = '/usr/lib/debug' . getLibdir() . '/' . $libName . '.debug';
        return [
            'depends' => [CreatePackages::getPrefix() . '-embed'],
            'files' => [
                $src => $target,
            ],
        ];
    }

    public function getLicense(): string
    {
        return 'PHP-3.01';
    }
}
