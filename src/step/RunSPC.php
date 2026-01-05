<?php

namespace staticphp\step;

use Exception;
use Symfony\Component\Process\Process;
use staticphp\util\TwigRenderer;

class RunSPC
{
    public static function run(bool $debug = false, string $phpVersion = '8.4', ?array $packages = null): bool
    {
        $craftYmlDest = BASE_PATH . '/craft.yml';

        // Render the template using the TwigRenderer
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

        $process = new Process($args, BASE_PATH, env: ['CI' => true]);
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

            // Copy the built files to our build directory
            self::copyBuiltFiles($phpVersion);

            return true;
        } catch (Exception $e) {
            echo "Error running static-php-cli with: " . $e->getMessage() . "\n";
            return false;
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
