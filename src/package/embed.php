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
        $binarySuffix = getBinarySuffix(); // e.g., "-zts", "-nts", "-zts8.5", or ""
        $libphp = 'libphp' . $binarySuffix . '.so';
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
        $binarySuffix = getBinarySuffix();
        // libphp filename with binary suffix: libphp-zts.so, libphp-nts.so, or libphp.so
        $libName = 'libphp' . $binarySuffix . '.so';

        $src = BUILD_ROOT_PATH . '/debug/libphp' . $binarySuffix . '.so.debug';
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
