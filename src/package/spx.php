<?php

namespace staticphp\package;

use staticphp\extension;
use staticphp\step\CreatePackages;

class spx extends extension
{
    public function getFpmConfig(): array
    {
        $versionedConflicts = CreatePackages::getVersionedConflicts('-spx');
        return [
            'config-files' => [
                getConfdir() . '/conf.d/20-spx.ini',
            ],
            'depends' => [
                CreatePackages::getPrefix() . '-cli'
            ],
            'provides' => [
                'php-zts-spx',
            ],
            'replaces' => $versionedConflicts,
            'conflicts' => $versionedConflicts,
            'files' => [
                BUILD_MODULES_PATH . '/spx.so' => getLibdir() . '/php-zts/modules/spx.so',
                $this->getIniPath() => getConfdir() . '/conf.d/20-spx.ini',
                BUILD_ROOT_PATH . '/share/misc/php-spx/assets/web-ui' => '/usr/share/php-zts/misc/php-spx/assets/web-ui',
            ]
        ];
    }

    public function getLicense(): string
    {
        return 'GPL-3.0';
    }
}
