<?php

namespace staticphp\package;

use staticphp\package;
use staticphp\CraftConfig;
use staticphp\step\CreatePackages;

class cli implements package
{
    public function getName(): string
    {
        return CreatePackages::getPrefix() . '-cli';
    }

    public function getFpmConfig(): array
    {
        $config = CraftConfig::getInstance();
        $staticExtensions = $config->getStaticExtensions();
        $prefix = CreatePackages::getPrefix();

        $contents = file_get_contents(INI_PATH . '/php.ini');
        $contents = str_replace('$libdir', getPhpLibdir(), $contents);
        // Replace ALL hardcoded /etc/php* paths with prefix-based conf dir
        $contents = preg_replace('#/etc/php[^/]*#', getConfdir(), $contents);
        file_put_contents(TEMP_DIR . '/php.ini', $contents);
        $provides = [$prefix];
        $versionedConflicts = CreatePackages::getVersionedConflicts('-cli');
        $replaces = $versionedConflicts;
        $conflicts = $versionedConflicts;
        $configFiles = [
            getConfdir(),
            getConfdir() . '/php.ini'
        ];
        $files = [
            TEMP_DIR . '/php.ini' => getConfdir() . '/php.ini',
            BUILD_BIN_PATH . '/php' => '/usr/bin/php' . getBinarySuffix(),
        ];

        foreach ($staticExtensions as $ext) {
            $provides[] = CreatePackages::getPrefix() . "-{$ext}";
            $replaces[] = CreatePackages::getPrefix() . "-{$ext}";

            // Add .ini files for statically compiled extensions
            $iniFile = INI_PATH . "/extension/{$ext}.ini";
            if (file_exists($iniFile)) {
                // Process the .ini file to replace ALL hardcoded php paths with prefix-based paths
                $iniContents = file_get_contents($iniFile);
                $iniContents = preg_replace(
                    [
                        '#/usr/share/php[^/]*/#',
                        '#/usr/local/share/php[^/]*/#',
                    ],
                    [
                        getSharedir() . '/',
                        '/usr/local/share/' . $prefix . '/',
                    ],
                    $iniContents
                );
                $tempIniPath = TEMP_DIR . "/{$ext}.ini";
                file_put_contents($tempIniPath, $iniContents);

                $files[$tempIniPath] = getConfdir() . "/conf.d/{$ext}.ini";
                $configFiles[] = getConfdir() . "/conf.d/{$ext}.ini";
            }
        }

        if (!file_exists(BUILD_ROOT_PATH . '/license/LICENSE')) {
            copy(BASE_PATH . '/LICENSE', BUILD_ROOT_PATH . '/license/LICENSE');
        }
        $files[BUILD_ROOT_PATH . '/license'] = '/usr/share/licenses/' . CreatePackages::getPrefix() . '/';

        return [
            'config-files' => $configFiles,
            'empty_directories' => [
                getSharedir() . '/preload',
                getVarLibdir() . '/session',
                getVarLibdir() . '/wsdlcache',
                getVarLibdir() . '/opcache',
            ],
            'directories' => [
                getSharedir() . '/preload',
                getVarLibdir() . '/session',
                getVarLibdir() . '/wsdlcache',
                getVarLibdir() . '/opcache',
            ],
            'provides' => $provides,
            'replaces' => $replaces,
            'conflicts' => $conflicts,
            'files' => $files
        ];
    }

    public function getFpmExtraArgs(): array
    {
        $binarySuffix = getBinarySuffix();
        $afterInstallScript = <<<BASH
#!/bin/sh
if [ ! -e /usr/bin/php ]; then
    ln -sf /usr/bin/php{$binarySuffix} /usr/bin/php
fi
BASH;
        $afterRemoveScript = <<<BASH
#!/bin/sh
if [ -L /usr/bin/php ] && [ "\$(readlink /usr/bin/php)" = "/usr/bin/php{$binarySuffix}" ]; then
    rm -f /usr/bin/php
fi
BASH;

        file_put_contents(TEMP_DIR . '/cli-after-install.sh', $afterInstallScript);
        file_put_contents(TEMP_DIR . '/cli-after-remove.sh', $afterRemoveScript);
        chmod(TEMP_DIR . '/cli-after-install.sh', 0755);
        chmod(TEMP_DIR . '/cli-after-remove.sh', 0755);

        return [
            '--after-install', TEMP_DIR . '/cli-after-install.sh',
            '--after-remove', TEMP_DIR . '/cli-after-remove.sh'
        ];
    }

    public function getDebuginfoFpmConfig(): array
    {
        $binarySuffix = getBinarySuffix();
        $src = BUILD_ROOT_PATH . '/debug/php' . $binarySuffix . '.debug';
        if (!file_exists($src)) {
            return [];
        }
        $target = '/usr/lib/debug/usr/bin/php' . $binarySuffix . '.debug';
        return [
            'depends' => [CreatePackages::getPrefix() . '-cli'],
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
