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
        $prefix = CreatePackages::getPrefix();
        $contents = file_get_contents(INI_PATH . '/php-fpm.conf');
        $contents = str_replace('@confdir@', getConfdir(), $contents);
        $contents = str_replace('@varlibdir@', getVarLibdir(), $contents);
        // Replace ALL hardcoded /var/log/php* paths with prefix-based paths
        $contents = preg_replace('#/var/log/php[^/]*/#', '/var/log/' . $prefix . '/', $contents);
        // Also replace /var/log/php-fpm.log with prefix-based path
        $contents = str_replace('/var/log/php-fpm.log', '/var/log/' . $prefix . '/php-fpm.log', $contents);
        // Replace ALL hardcoded /var/lib/php* paths with prefix-based paths
        $contents = preg_replace('#/var/lib/php[^/]*/#', getVarLibdir() . '/', $contents);
        // Replace ALL hardcoded /run/php-fpm* paths with prefix-based paths
        $contents = preg_replace('#/run/php-fpm[^/]*/#', '/run/php-fpm' . getBinarySuffix() . '/', $contents);
        file_put_contents(TEMP_DIR . '/php-fpm.conf', $contents);

        // Process the systemd service file to replace ALL hardcoded paths
        $serviceContents = file_get_contents(INI_PATH . '/php-fpm.service');
        $binarySuffix = getBinarySuffix();
        $serviceContents = preg_replace(
            [
                '#/usr/sbin/php-fpm[^ ]*#',
                '#RuntimeDirectory=php-fpm[^ ]*#',
            ],
            [
                '/usr/sbin/php-fpm' . $binarySuffix,
                'RuntimeDirectory=php-fpm' . $binarySuffix,
            ],
            $serviceContents
        );
        file_put_contents(TEMP_DIR . '/php-fpm.service', $serviceContents);

        // Process www.conf to replace ALL hardcoded paths
        $wwwContents = file_get_contents(INI_PATH . '/www.conf');
        $wwwContents = str_replace('@varlibdir@', getVarLibdir(), $wwwContents);
        $wwwContents = preg_replace(
            [
                '#/var/lib/php[^/]*/#',
                '#/var/log/php[^/]*/#',
                '#/var/log/php-fpm/#',
                '#/run/php-fpm[^/]*/#',
            ],
            [
                getVarLibdir() . '/',
                '/var/log/' . $prefix . '/',
                '/var/log/' . $prefix . '/',
                '/run/php-fpm' . $binarySuffix . '/',
            ],
            $wwwContents
        );
        file_put_contents(TEMP_DIR . '/www.conf', $wwwContents);

        $versionedConflicts = CreatePackages::getVersionedConflicts('-fpm');
        return [
            'depends' => [
                CreatePackages::getPrefix() . '-cli',
            ],
            'provides' => [],
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
        $binarySuffix = getBinarySuffix();
        $src = BUILD_ROOT_PATH . '/debug/php-fpm.debug';
        if (!file_exists($src)) {
            return [];
        }
        $target = '/usr/lib/debug/usr/sbin/php-fpm' . $binarySuffix . '.debug';
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

    public function getDescription(): string
    {
        return 'FPM SAPI for PHP';
    }
}
