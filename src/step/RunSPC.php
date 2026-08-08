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

        try {
            $exitCode = $process->run(function ($type, $buffer) {
                echo $buffer;
            });
        } catch (Exception $e) {
            // run() only throws for failures to *launch* the process, not for a
            // non-zero exit, so the message here is short and safe to print.
            echo "Error launching static-php-cli: " . $e->getMessage() . "\n";
            return false;
        }

        if ($exitCode !== 0) {
            echo "Static PHP CLI build failed (exit code {$exitCode}). See the streamed output above.\n";
            return false;
        }

        echo "Static PHP CLI build completed successfully.\n";

        // Copy the built files to our build directory (only when we actually built PHP)
        if (!$libsOnly) {
            self::copyBuiltFiles($phpVersion);
        }

        return true;
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

        // Globs miss dotfiles, so spc's .build.json never arrives and stale ones never leave;
        // cp -a also preserves the <ext>.so -> <ext>-zts-NN.so symlinks that cp -r dereferences.
        exec('rm -rf ' . escapeshellarg($buildDir));
        if (!is_dir($buildDir) && !mkdir($buildDir, 0755, true) && !is_dir($buildDir)) {
            echo "Failed to create directory: {$buildDir}\n";
            return;
        }
        exec('cp -a ' . escapeshellarg($sourceDir) . '/. ' . escapeshellarg($buildDir) . '/');

        echo "Copied PHP {$phpVersion} files to {$buildDir}\n";
    }
}
