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
        $prefix = getBinarySuffix(); // e.g., "-zts", "-nts", "-zts8.5", or ""
        // SPC produces libphp-{prefix}-{version}.so with only leading dash removed from prefix
        // e.g., "-zts" -> "libphp-zts-85.so", "-zts8.5" -> "libphp-zts8.5-85.so", "" -> "libphp-85.so"
        $releasePrefix = ltrim($prefix, '-');
        $libphp = $releasePrefix !== ''
            ? 'libphp-' . $releasePrefix . '-' . $phpVersion . '.so'
            : 'libphp-' . $phpVersion . '.so';
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
        // SPC produces libphp-{prefix}-{version}.so with only leading dash removed from prefix
        $releasePrefix = ltrim($prefix, '-');
        $libName = $releasePrefix !== ''
            ? 'libphp-' . $releasePrefix . '-' . $phpVersionDigits . '.so'
            : 'libphp-' . $phpVersionDigits . '.so';

        // Debug file is just libphp.so.debug (without version/prefix)
        $src = BUILD_ROOT_PATH . '/debug/libphp.so.debug';
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
