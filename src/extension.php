<?php

namespace staticphp;

use Exception;
use staticphp\step\CreatePackages;
use staticphp\util\ExtMeta;
use staticphp\util\TwigRenderer;

class extension implements package
{
    private string $prefix;

    public function __construct(
        private readonly string $name,
    )
    {
        $this->prefix = $this->determineExtensionPrefix();
    }

    private function determineExtensionPrefix(): string
    {
        if (!$this->isSharedExtension()) {
            return '';
        }

        if ($this->name === 'xdebug' || $this->name === 'ffi') {
            return '15-';
        }
        if (ExtMeta::isZendExtension($this->name)) {
            return '10-';
        }

        $allDependencies = $this->getExtensionDependencies($this->name);

        if (empty($allDependencies)) {
            return '20-';
        }

        return '30-';
    }

    public function getExtensionDependencies(string $extensionName, array $visited = []): array
    {
        if (ExtMeta::get($extensionName) === null) {
            return [];
        }

        $allDependencies = [];
        $visited[] = $extensionName;
        $craftConfig = CraftConfig::getInstance();

        foreach (ExtMeta::extDependencies($extensionName) as $dependency) {
            if (!in_array($dependency, $craftConfig->getSharedExtensions()) || in_array($dependency, $craftConfig->getStaticExtensions())) {
                continue;
            }

            if (in_array($dependency, $visited)) {
                continue;
            }

            if (ExtMeta::extDependencies($dependency) !== []) {
                $transitiveDeps = $this->getExtensionDependencies($dependency, $visited);

                foreach ($transitiveDeps as $transitiveDep) {
                    if (!in_array($transitiveDep, $craftConfig->getSharedExtensions()) || in_array($transitiveDep, $craftConfig->getStaticExtensions())) {
                        continue;
                    }
                    if (!in_array($transitiveDep, $allDependencies)) {
                        $allDependencies[] = $transitiveDep;
                    }
                }
            }

            $allDependencies[] = $dependency;
        }

        return $allDependencies;
    }

    public function getFpmConfig(): array
    {
        if (ExtMeta::get($this->name) === null) {
            throw new Exception("Extension configuration for '{$this->name}' not found.");
        }
        $prefix = CreatePackages::getPrefix();
        $depends = [$prefix . '-cli'];
        $seen = [];
        $ordered = [];

        $collect = function (string $name) use (&$collect, &$ordered, &$seen, $prefix): void {
            if (isset($seen[$name])) {
                return;
            }
            $seen[$name] = true;

            if (ExtMeta::get($name) === null) {
                return;
            }
            if (!ExtMeta::isAddon($name)) {
                $ordered[] = $prefix . '-' . $name;
            }
            foreach (ExtMeta::extDependencies($name) as $dep) {
                $collect($dep);
            }
        };

        foreach (ExtMeta::extDependencies($this->name) as $dep) {
            $collect($dep);
        }

        $depends = array_merge($depends, $ordered);

        $versionedConflicts = CreatePackages::getVersionedConflicts('-' . $this->name);
        return [
            'config-files' => [
                getConfdir() . '/conf.d/' . $this->prefix . $this->name . '.ini',
            ],
            'depends' => $depends,
            'provides' => [],
            'replaces' => $versionedConflicts,
            'conflicts' => $versionedConflicts,
            'files' => [
                ...($this->getIniPath() ?
                    [$this->getIniPath() => getConfdir() . '/conf.d/' . $this->prefix . $this->name . '.ini']
                    : []
                ),
                ...($this->isSharedExtension() ?
                    [BUILD_MODULES_PATH . '/' . $this->name . getSharedLibrarySuffix() . '.so' => getModuledir() . '/' . $this->name . getSharedLibrarySuffix() . '.so']
                    : []
                ),
            ]
        ];
    }

    protected function getIniPath(): ?string
    {
        $iniPath = INI_PATH . '/extension/' . $this->name . '.ini';
        if (!file_exists($iniPath)) {
            return null;
        }

        $tempIniPath = TEMP_DIR . '/' . $this->prefix . $this->name . '.ini';

        // Get the dynamic prefix for path replacements
        $prefix = CreatePackages::getPrefix();

        $iniContent = TwigRenderer::renderFile($iniPath, [
            'type' => defined('SPP_TYPE') ? SPP_TYPE : 'rpm',
            'binary_suffix' => getBinarySuffix(),
            'shared_library_suffix' => getSharedLibrarySuffix(),
            'is_shared' => $this->isSharedExtension(),
        ]);

        $iniContent = preg_replace(
            [
                '#/usr/share/php[^/]*/#',
                '#/usr/local/share/php[^/]*/#',
            ],
            [
                '/usr/share/' . $prefix . '/',
                '/usr/local/share/' . $prefix . '/',
            ],
            $iniContent
        );
        file_put_contents($tempIniPath, $iniContent);

        return $tempIniPath;
    }

    public function isSharedExtension(): bool
    {
        $craftConfig = CraftConfig::getInstance();
        return in_array($this->name, $craftConfig->getSharedExtensions()) && !in_array($this->name, $craftConfig->getStaticExtensions());
    }

    public function getFpmExtraArgs(): array
    {
        return [];
    }

    public function getDebuginfoFpmConfig(): array
    {
        if (!$this->isSharedExtension()) {
            return [];
        }
        $sharedLibrarySuffix = getSharedLibrarySuffix();
        $src = BUILD_ROOT_PATH . '/debug/' . $this->name . $sharedLibrarySuffix . '.so.debug';
        if (!file_exists($src)) {
            return [];
        }
        $targetSo = getModuledir() . '/' . $this->name . $sharedLibrarySuffix . '.so';
        $target = '/usr/lib/debug' . $targetSo . '.debug';
        return [
            'depends' => [CreatePackages::getPrefix() . '-' . $this->name],
            'files' => [
                $src => $target,
            ],
        ];
    }

    public function getName(): string
    {
        return CreatePackages::getPrefix() . '-' . $this->name;
    }

    public function getLicense(): string
    {
        if ($this->name === 'xdebug') {
            return 'Xdebug-1.03';
        }
        return 'PHP-3.01';
    }

    public function getDescription(): string
    {
        return ucfirst($this->name) . ' extension for PHP';
    }
}
