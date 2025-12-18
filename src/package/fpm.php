<?php

namespace staticphp\package;

use staticphp\package;
use staticphp\step\CreatePackages;

class fpm implements package
{
    public function getName(): string
    {
        return CreatePackages::getPrefix() . '-fpm';
    }

    public function getFpmConfig(): array
    {
        $contents = file_get_contents(INI_PATH . '/php-fpm.conf');
        $contents = str_replace('$confdir', getConfdir(), $contents);
        file_put_contents(TEMP_DIR . '/php-fpm.conf', $contents);

        // Process the systemd service file to replace hardcoded paths
        $serviceContents = file_get_contents(INI_PATH . '/php-fpm.service');
        $binarySuffix = getBinarySuffix();
        $serviceContents = str_replace(
            [
                '/usr/sbin/php-fpm-zts',
                'RuntimeDirectory=php-fpm-zts',
            ],
            [
                '/usr/sbin/php-fpm' . $binarySuffix,
                'RuntimeDirectory=php-fpm' . $binarySuffix,
            ],
            $serviceContents
        );
        file_put_contents(TEMP_DIR . '/php-fpm.service', $serviceContents);

        // Process www.conf to replace hardcoded paths
        $wwwContents = file_get_contents(INI_PATH . '/www.conf');
        $wwwContents = str_replace(
            '/var/lib/php-zts/',
            getVarLibdir() . '/',
            $wwwContents
        );
        file_put_contents(TEMP_DIR . '/www.conf', $wwwContents);

        $versionedConflicts = CreatePackages::getVersionedConflicts('-fpm');
        return [
            'depends' => [
                CreatePackages::getPrefix() . '-cli',
            ],
            'provides' => [
                'php-zts-fpm',
            ],
            'replaces' => $versionedConflicts,
            'conflicts' => $versionedConflicts,
            'files' => [
                TEMP_DIR . '/php-fpm.conf' => getConfdir() . '/php-fpm.conf',
                TEMP_DIR . '/www.conf' => getConfdir() . '/fpm.d/www.conf',
                TEMP_DIR . '/php-fpm.service' => '/usr/lib/systemd/system/php-fpm' . getBinarySuffix() . '.service',
                BUILD_BIN_PATH . '/php-fpm' => '/usr/sbin/php-fpm' . getBinarySuffix(),
            ],
            'empty_directories' => [
                getConfdir() . '/fpm.d/',
                '/var/log/' . CreatePackages::getPrefix() . '/php-fpm',
            ],
            'directories' => [
                getConfdir() . '/fpm.d/',
                '/var/log/' . CreatePackages::getPrefix() . '/php-fpm',
            ],
        ];
    }

    public function getFpmExtraArgs(): array
    {
        return [];
    }

    public function getDebuginfoFpmConfig(): array
    {
        $src = BUILD_ROOT_PATH . '/debug/php-fpm-zts.debug';
        if (!file_exists($src)) {
            return [];
        }
        $target = '/usr/lib/debug/usr/sbin/php-fpm' . getBinarySuffix() . '.debug';
        return [
            'depends' => [CreatePackages::getPrefix() . '-fpm'],
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
