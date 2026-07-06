<?php

namespace staticphp\step;

use Exception;
use Symfony\Component\Process\Process;
use staticphp\util\TwigRenderer;

class RunSPC
{
    public static function run(bool $debug = false, string $phpVersion = '8.4', ?array $packages = null, bool $libsOnly = false): bool
    {
        $craftYmlDest = BASE_PATH . '/craft.yml';

        try {
            $craftYml = TwigRenderer::renderCraftTemplate($phpVersion, null, $packages);

            // Write the rendered craft.yml to the destination
            if (!file_put_contents($craftYmlDest, $craftYml)) {
                echo "Failed to write updated craft.yml to project root.\n";
                return false;
            }
        } catch (Exception $e) {
            echo "Error rendering craft.yml template: " . $e->getMessage() . "\n";
            return false;
        }

        self::hardenZigCcWrappers();

        // Build the command arguments
        $args = ['vendor/bin/spc', 'craft'];
        if ($debug) {
            $args[] = '--debug';
        }
        if ($libsOnly) {
            $args[] = '--libs-only';
        }

        $env = getenv('GITHUB_ACTIONS') ? ['CI' => true] : [];
        $process = new Process($args, BASE_PATH, env: $env);
        $process->setTimeout(null);
        if (Process::isTtySupported()) {
            $process->setTty(true); // Interactive mode
        }

        // Run the process
        try {
            $process->mustRun(function ($type, $buffer) {
                echo $buffer;
            });

            echo "Static PHP CLI build completed successfully.\n";

            // Copy the built files to our build directory (only when we actually built PHP)
            if (!$libsOnly) {
                self::copyBuiltFiles($phpVersion);
            }

            return true;
        } catch (Exception $e) {
            echo "Error running static-php-cli with: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Work around a zig 0.16 failure mode in spc's zig-cc / zig-c++ wrapper scripts
     * (vendor/crazywhalecc/static-php-cli/src/SPC/store/scripts/zig-cc.sh):
     *
     * When the `zig cc` child process (usually `zig ld.lld` during the LTO link of
     * sapi/cli/php) dies from a signal such as SIGSEGV, zig aborts WITHOUT printing
     * anything, so the build log only shows "Aborted (core dumped)" with no clue.
     * On glibc-versioned targets (alma), the wrapper additionally captures the first
     * attempt's output and discards it before blindly re-running via exec.
     *
     * This rewrites the wrappers' final exec so that a signal-death is logged loudly
     * (including any captured output) and retried once, since these crashes have been
     * one-off per runner in CI.
     */
    private static function hardenZigCcWrappers(): void
    {
        $pkgRoot = getenv('PKG_ROOT_PATH');
        if (!is_string($pkgRoot) || $pkgRoot === '') {
            return;
        }

        foreach (['zig-cc', 'zig-c++'] as $wrapper) {
            $path = "{$pkgRoot}/zig/{$wrapper}";
            if (!is_file($path)) {
                continue;
            }
            $content = file_get_contents($path);
            if ($content === false || str_contains($content, 'terminated by signal')) {
                continue; // unreadable or already patched
            }

            $patched = preg_replace(
                '/^exec (zig (?:cc|c\+\+) \$TARGET \$SPC_COMPILER_EXTRA "\$\{PARSED_ARGS\[@]}")$/m',
                <<<'BASH'
                $1
                rc=$?
                if [[ $rc -ge 128 ]]; then
                    echo "zig-cc: zig terminated by signal $((rc-128)) (exit code $rc); retrying once" >&2
                    [[ -n "$output" ]] && echo "$output" >&2
                    $1
                    rc=$?
                fi
                if [[ $rc -ge 128 ]]; then
                    # A second identical death means per-machine state is poisoned: the link consumes
                    # Scrt1.o/libc++/libunwind/compiler_rt etc. from zig's global cache, which is
                    # populated concurrently on first use and can be corrupted by a race. Rebuild it.
                    zig_cache="${ZIG_GLOBAL_CACHE_DIR:-$HOME/.cache/zig}"
                    echo "zig-cc: zig terminated by signal $((rc-128)) again; purging zig cache ($zig_cache) and retrying" >&2
                    rm -rf "$zig_cache" 2>/dev/null
                    $1
                    rc=$?
                    [[ $rc -ge 128 ]] && echo "zig-cc: zig terminated by signal $((rc-128)) even after cache purge (exit code $rc)" >&2
                fi
                exit $rc
                BASH,
                $content,
                1,
                $count
            );

            if ($patched !== null && $count === 1 && file_put_contents($path, $patched) !== false) {
                echo "Hardened {$wrapper} wrapper (log + retry on zig signal death): {$path}\n";
            }
        }
    }

    private static function copyBuiltFiles(string $phpVersion): void
    {
        // Copy the built PHP binaries to our build directory
        $sourceDir = BASE_PATH . '/buildroot';
        $buildDir = BUILD_ROOT_PATH;
        $baseBuildDir = BASE_PATH . '/build';

        // Create the base build directory if it doesn't exist
        if (!is_dir($baseBuildDir) && !mkdir($baseBuildDir, 0755, true) && !is_dir($baseBuildDir)) {
            echo "Failed to create directory: {$baseBuildDir}\n";
            return;
        }

        // Check for existing PHP versions in the build directory
        $existingVersions = [];
        if (is_dir($baseBuildDir)) {
            $dirs = scandir($baseBuildDir);
            foreach ($dirs as $dir) {
                if ($dir !== '.' && $dir !== '..' && is_dir($baseBuildDir . '/' . $dir)) {
                    // Check if this directory contains a PHP binary
                    $versionBinary = $baseBuildDir . '/' . $dir . '/bin/php';
                    if (file_exists($versionBinary)) {
                        // Get the PHP version from the binary
                        $versionProcess = new Process([$versionBinary, '-r', 'echo PHP_VERSION;']);
                        $versionProcess->run();
                        $detectedVersion = trim($versionProcess->getOutput());

                        if (!empty($detectedVersion)) {
                            // Extract major.minor version
                            $parts = explode('.', $detectedVersion);
                            if (count($parts) >= 2) {
                                $majorMinor = $parts[0] . '.' . $parts[1];
                                echo "Found PHP version {$detectedVersion} (major.minor: {$majorMinor}) in directory {$dir}\n";
                                $existingVersions[$dir] = $majorMinor;
                            }
                        }
                    }
                }
            }
        }

        // Create the build directory if it doesn't exist
        if (!is_dir($buildDir) && !mkdir($buildDir, 0755, true) && !is_dir($buildDir)) {
            echo "Failed to create directory: {$buildDir}\n";
            return;
        }

        // Clean and copy files
        exec("rm -rf {$buildDir}/*");
        exec("cp -r {$sourceDir}/* {$buildDir}");

        echo "Copied PHP {$phpVersion} files to {$buildDir}\n";
    }
}
