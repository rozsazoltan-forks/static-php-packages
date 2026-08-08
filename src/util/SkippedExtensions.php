<?php

declare(strict_types=1);

namespace staticphp\util;

/**
 * Reader for BUILD_ROOT_PATH/skipped-shared-extensions.json, static-php-cli's record of the
 * shared extensions a build dropped instead of failing on. Tolerance is gated on the manifest's
 * own allow_shared_ext_failure flag, which spp turns on only for PHP >= 8.6; when it is false,
 * or the manifest is absent, nothing excuses a missing .so.
 */
final class SkippedExtensions
{
    /** @var array<string,string>|null [extension => "<phase> failure: <message>"] */
    private static ?array $skipped = null;

    private static bool $available = false;

    private static bool $tolerant = false;

    /** @return array<string,string> [extension => "<phase> failure: <message>"] */
    public static function load(): array
    {
        if (self::$skipped !== null) {
            return self::$skipped;
        }

        self::$skipped = [];
        if (!defined('BUILD_ROOT_PATH')) {
            return self::$skipped;
        }
        // Only BUILD_ROOT_PATH is consulted: RunSPC::copyBuiltFiles() copies spc's whole
        // buildroot/ into the per-version build dir, so the manifest always arrives with
        // the .so files it describes. Reading spc's buildroot/ directly instead would read
        // whichever PHP version was built there last.
        $path = BUILD_ROOT_PATH . '/skipped-shared-extensions.json';
        if (!is_file($path)) {
            return self::$skipped;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            echo "Warning: unreadable skipped-extension manifest at {$path}\n";
            return self::$skipped;
        }
        if (!self::versionMatches($data['php_version_id'] ?? null, $path)) {
            return self::$skipped;
        }
        self::$available = true;
        self::$tolerant = ($data['allow_shared_ext_failure'] ?? false) === true;
        $entries = is_array($data['skipped'] ?? null) ? $data['skipped'] : [];
        if (!self::$tolerant) {
            if ($entries !== []) {
                echo "Warning: {$path} lists skipped extensions but allow_shared_ext_failure is off — ignoring it, missing extensions stay fatal\n";
            }
            return self::$skipped;
        }
        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry['extension'])) {
                continue;
            }
            $phase = (string) ($entry['phase'] ?? 'unknown');
            self::$skipped[(string) $entry['extension']] = "{$phase} failure: " . (string) ($entry['message'] ?? '');
        }
        ksort(self::$skipped);

        return self::$skipped;
    }

    /**
     * Extensions this build must not package: those static-php-cli recorded as skipped, plus
     * every declared extension that hard-`depends` on one. Shipping a dependent of a missing
     * extension would install a package that cannot work; `suggests` edges are pruned from the
     * dependency list instead, in extension::getFpmConfig().
     *
     * Both the packaging step and the post-install test resolve the set through here — deriving
     * it twice would fail a build over an extension packaging deliberately left out.
     *
     * @param  list<string> $sharedExtensions every shared extension craft.yml declared
     * @return array<string,string> [extension => reason]
     */
    public static function resolveFor(array $sharedExtensions): array
    {
        // Addons are configure flags compiled into their parent's .so — never built or packaged
        // on their own, so propagating a skip to them would only pad the report.
        $candidates = array_values(array_filter($sharedExtensions, fn($e) => !ExtMeta::isAddon($e)));

        $skipped = [];
        foreach (self::load() as $extension => $reason) {
            if (in_array($extension, $candidates, true)) {
                $skipped[$extension] = $reason;
            }
        }
        if ($skipped === []) {
            return [];
        }

        do {
            $added = false;
            foreach ($candidates as $extension) {
                if (isset($skipped[$extension])) {
                    continue;
                }
                foreach (ExtMeta::extDependencies($extension, false) as $dependency) {
                    if (isset($skipped[$dependency])) {
                        $skipped[$extension] = "depends on skipped extension {$dependency}";
                        $added = true;
                        break;
                    }
                }
            }
        } while ($added);

        ksort($skipped);
        return $skipped;
    }

    /** True when a manifest was found at all, i.e. static-php-cli is new enough to record skips. */
    public static function isAvailable(): bool
    {
        self::load();
        return self::$available;
    }

    /**
     * True only when the build that produced the manifest ran with
     * allow-shared-ext-failure ON. Every code path that tolerates a missing extension
     * must check this: for PHP 8.5 and earlier it is false and an unexplained absence
     * is still an error.
     */
    public static function isTolerant(): bool
    {
        self::load();
        return self::$tolerant;
    }

    /**
     * A build dir is a copy of spc's single buildroot/, so a manifest from another PHP version
     * can land next to this version's binaries — accepting it would let an 8.6 build's tolerance
     * excuse a missing extension on 8.5. Anything that does not positively match is refused.
     */
    private static function versionMatches(mixed $versionId, string $path): bool
    {
        $expected = defined('SPP_PHP_VERSION') && preg_match('/^(\d+\.\d+)/', SPP_PHP_VERSION, $m) ? $m[1] : null;
        $actual = is_int($versionId) ? sprintf('%d.%d', intdiv($versionId, 10000), intdiv($versionId % 10000, 100)) : null;
        if ($expected !== null && $actual === $expected) {
            return true;
        }
        echo "Warning: ignoring {$path}: it records PHP " . ($actual ?? 'an unknown version')
            . ' but this run packages PHP ' . ($expected ?? 'an unknown version')
            . " — missing extensions stay fatal\n";
        return false;
    }
}
