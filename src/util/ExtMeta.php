<?php

declare(strict_types=1);

namespace staticphp\util;

use StaticPHP\Config\PackageConfig;

/**
 * Thin v3-API wrapper for the extension metadata fields the spp packaging
 * step needs (deps/addon/zend-ext detection). v2 used SPC\store\Config::getExt;
 * v3 splits the same data between top-level package keys and the
 * `php-extension` sub-block, with `depends`/`suggests` lists carrying both
 * lib- and ext-prefixed entries.
 */
final class ExtMeta
{
    /** @return array<string, mixed>|null */
    public static function get(string $extName): ?array
    {
        $cfg = PackageConfig::get('ext-' . $extName);
        return is_array($cfg) ? $cfg : null;
    }

    /** @return list<string> short ext names (without the "ext-" prefix) */
    public static function extDependencies(string $extName, bool $include_suggests = true): array
    {
        $kinds = $include_suggests ? ['depends', 'suggests'] : ['depends'];
        $out = [];
        foreach ($kinds as $kind) {
            foreach ((array) PackageConfig::get('ext-' . $extName, $kind, []) as $dep) {
                if (is_string($dep) && str_starts_with($dep, 'ext-')) {
                    $short = substr($dep, 4);
                    if (!in_array($short, $out, true)) {
                        $out[] = $short;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * v2 marked virtual extensions (e.g. swoole-hook-pgsql) with `type: addon`.
     * v3 expresses the same shape via `arg-type: none` + `display-name` pointing
     * at the parent extension.
     */
    public static function isAddon(string $extName): bool
    {
        $cfg = self::get($extName);
        $ext = $cfg['php-extension'] ?? [];
        return ($ext['arg-type'] ?? null) === 'none'
            && isset($ext['display-name'])
            && $ext['display-name'] !== $extName;
    }

    public static function isZendExtension(string $extName): bool
    {
        $cfg = self::get($extName);
        return (bool) ($cfg['php-extension']['zend-extension'] ?? false);
    }
}
