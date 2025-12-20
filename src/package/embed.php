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
        $prefix = getBinarySuffix(); // e.g., "-zts", "-nts", "-zts8.5"
        $libphp = 'libphp' . $prefix . '-' . $phpVersion . '.so';
        $versionedConflicts = CreatePackages::getVersionedConflicts('-embed');
        $provides = [
            $libphp,
            CreatePackages::getPrefix() . '-embedded'
        ];
        if ($this->getName() !== CreatePackages::getPrefix() . '-embed') {
            $provides[] = CreatePackages::getPrefix() . '-embed';
        }
        return [
            'depends' => [
                CreatePackages::getPrefix() . '-cli',
            ],
            'provides' => $provides,
            'replaces' => $versionedConflicts,
            'conflicts' => $versionedConflicts,
            'files' => [
                BUILD_LIB_PATH . '/' . $libphp => getLibdir() . '/' . $libphp,
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
        $prefix = getBinarySuffix();
        $libName = 'libphp' . $prefix . '-' . $phpVersionDigits . '.so';
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
