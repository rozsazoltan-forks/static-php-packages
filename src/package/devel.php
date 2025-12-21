<?php

namespace staticphp\package;

use staticphp\package;
use staticphp\step\CreatePackages;

class devel implements package
{
    public function getName(): string
    {
        return CreatePackages::getPrefix() . '-devel';
    }
    public function getFpmConfig(): array
    {
        $phpConfigPath = BUILD_BIN_PATH . '/php-config';
        $modifiedPhpConfigPath = TEMP_DIR . '/php-config';

        $phpConfigContent = file_get_contents($phpConfigPath);

        // Replace buildroot paths with BUILD_ROOT_PATH
        $builtDir = BASE_PATH . '/vendor/crazywhalecc/static-php-cli/buildroot';
        $phpConfigContent = str_replace($builtDir, BUILD_ROOT_PATH, $phpConfigContent);
        $phpConfigContent = str_replace('/app/buildroot', BUILD_ROOT_PATH, $phpConfigContent);

        $binarySuffix = getBinarySuffix();
        $phpConfigContent = preg_replace(
            [
                '/^prefix=.*$/m',
                '/^ldflags=.*$/m',
                '/^libs=.*$/m',
                '/^program_prefix=.*$/m',
                '/^program_suffix=.*$/m',
            ],
            [
                'prefix="/usr"',
                'ldflags="-lpthread"',
                'libs=""',
                'program_prefix=""',
                'program_suffix="' . $binarySuffix . '"',
            ],
            $phpConfigContent
        );

        // Replace all /php paths with versioned paths
        $phpConfigContent = preg_replace('#/php(?!' . preg_quote($binarySuffix, '#') . ')#', '/' . CreatePackages::getPrefix(), $phpConfigContent);
        $phpVersion = str_replace('.', '', SPP_PHP_VERSION);
        // Use release prefix format for libphp (leading dash removed, dots kept)
        $releasePrefix = ltrim($binarySuffix, '-');
        $libName = $releasePrefix !== ''
            ? 'libphp-' . $releasePrefix . '-' . $phpVersion . '.so'
            : 'libphp-' . $phpVersion . '.so';
        $phpConfigContent = str_replace('libphp.so', $libName, $phpConfigContent);

        // For APK, sed is in /bin/sed instead of /usr/bin/sed
        if (defined('SPP_TYPE') && SPP_TYPE === 'apk') {
            $phpConfigContent = str_replace('/usr/bin/sed', '/bin/sed', $phpConfigContent);
        }

        file_put_contents($modifiedPhpConfigPath, $phpConfigContent);
        chmod($modifiedPhpConfigPath, 0755);

        $phpizePath = BUILD_BIN_PATH . '/phpize';
        $modifiedPhpizePath = TEMP_DIR . '/phpize';

        $phpizeContent = file_get_contents($phpizePath);

        // Replace buildroot paths with BUILD_ROOT_PATH
        $phpizeContent = str_replace($builtDir, BUILD_ROOT_PATH, $phpizeContent);
        $phpizeContent = str_replace('/app/buildroot', BUILD_ROOT_PATH, $phpizeContent);

        $phpizeContent = preg_replace(
            [
                '/^prefix=.*$/m',
                '/^datarootdir=.*$/m',
            ],
            [
                'prefix="/usr"',
                'datarootdir="/' . \staticphp\step\CreatePackages::getPrefix() . '"',
            ],
            $phpizeContent
        );
        $phpizeContent = str_replace(
            [
                'lib/php`',
                '"`eval echo ${prefix}/include`/php"'
            ],
            [
                str_replace('/usr/', '', getPhpLibdir()) . '`',
                '"`eval echo ${prefix}/include`/' . \staticphp\step\CreatePackages::getPrefix() . '"'
            ],
            $phpizeContent
        );

        // For APK, sed is in /bin/sed instead of /usr/bin/sed
        if (defined('SPP_TYPE') && SPP_TYPE === 'apk') {
            $phpizeContent = str_replace('/usr/bin/sed', '/bin/sed', $phpizeContent);
        }

        file_put_contents($modifiedPhpizePath, $phpizeContent);
        chmod($modifiedPhpizePath, 0755);

        $versionedConflicts = CreatePackages::getVersionedConflicts('-devel');

        return [
            'files' => [
                $modifiedPhpConfigPath => '/usr/bin/php-config' . getBinarySuffix(),
                $modifiedPhpizePath => '/usr/bin/phpize' . getBinarySuffix(),
                BUILD_INCLUDE_PATH . '/php/' => '/usr/include/' . \staticphp\step\CreatePackages::getPrefix(),
                BUILD_LIB_PATH . '/php/build' => getPhpLibdir() . '/build',
            ],
            'depends' => [CreatePackages::getPrefix() . '-cli'],
            'provides' => [
                'php-config' . getBinarySuffix(),
                'phpize' . getBinarySuffix(),
            ],
            'replaces' => $versionedConflicts,
            'conflicts' => $versionedConflicts,
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
