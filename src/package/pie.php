<?php

namespace staticphp\package;

use SPC\store\CurlHook;
use SPC\store\Downloader;
use staticphp\package;
use staticphp\step\CreatePackages;
use Symfony\Component\Process\Process;

class pie implements package
{
    public function getName(): string
    {
        return 'pie-' . CreatePackages::getPrefix();
    }

    /**
     * Return the PIE application version (e.g., 1.3.1) parsed from `pie.phar -V`.
     * CreatePackages will use this as the package version when available.
     */
    public function getVersion(): string
    {
        // Ensure artifacts exist and get the staged phar path
        [$pharSource] = $this->prepareArtifacts();

        $proc = new Process(['php', $pharSource, '-V'], env: self::getCleanEnvironment());
        $proc->setTimeout(30);
        $proc->run();
        if (!$proc->isSuccessful()) {
            // Include both stdout and stderr for parsing attempt/fallback
            $output = $proc->getOutput() . "\n" . $proc->getErrorOutput();
        } else {
            $output = $proc->getOutput() . "\n" . $proc->getErrorOutput();
        }

        // Example: "🥧 PHP Installer for Extensions (PIE) 1.3.1"
        if (preg_match('/\(PIE\)\s+([0-9][0-9A-Za-z.-]*)/u', $output, $m)) {
            return $m[1];
        }
        if (preg_match('/PIE\s+([0-9][0-9A-Za-z.-]*)/u', $output, $m)) {
            return $m[1];
        }

        throw new \RuntimeException('Unable to detect PIE version from output: ' . trim($output));
    }
    public function getFpmConfig(): array
    {
        [$pharSource, $wrapperSource] = $this->prepareArtifacts();

        $prefix = CreatePackages::getPrefix();

        // Get versioned conflicts for pie packages (pie-php-zts8.0, pie-php-zts8.1, etc.)
        $phpConflicts = CreatePackages::getVersionedConflicts('');
        $versionedConflicts = [];
        foreach ($phpConflicts as $conflict) {
            $versionedConflicts[] = 'pie-' . $conflict;
        }

        return [
            'depends' => [
                $prefix . '-cli',
                $prefix . '-devel',
            ],
            'provides' => [
                'pie-zts',
            ],
            'replaces' => $versionedConflicts,
            'conflicts' => $versionedConflicts,
            'files' => [
                $pharSource => getSharedir() . '/pie.phar',
                $wrapperSource => '/usr/bin/pie' . getBinarySuffix(),
            ],
        ];
    }

    public function getDebuginfoFpmConfig(): array
    {
        return [];
    }

    public function getFpmExtraArgs(): array
    {
        return [];
    }

    public function getLicense(): string
    {
        return 'BSD-3-Clause';
    }

    /**
     * Get environment without Xdebug variables that would cause connection attempts
     */
    private static function getCleanEnvironment(): array
    {
        $env = $_SERVER;

        // Explicitly disable Xdebug-related environment variables
        // Must be set to empty/0, not unset, as they inherit from parent
        $env['XDEBUG_SESSION'] = '';
        $env['XDEBUG_CONFIG'] = '';
        $env['XDEBUG_MODE'] = 'off';
        $env['PHP_IDE_CONFIG'] = '';

        return $env;
    }

    private function prepareArtifacts(): array
    {
        $pharPath = DOWNLOAD_PATH . '/pie.phar';
        if (!file_exists($pharPath)) {
            $this->downloadLatestPiePhar($pharPath);
        }

        // Process the pie wrapper script to replace hardcoded paths
        $wrapperSource = INI_PATH . '/pie-zts';
        $wrapperPath = TEMP_DIR . '/pie' . getBinarySuffix();
        $wrapperContents = file_get_contents($wrapperSource);
        $binarySuffix = getBinarySuffix();

        $wrapperContents = str_replace(
            [
                '/usr/bin/php-zts',
                '/usr/share/php-zts/',
                '/usr/bin/php-config-zts',
            ],
            [
                '/usr/bin/php' . $binarySuffix,
                getSharedir() . '/',
                '/usr/bin/php-config' . $binarySuffix,
            ],
            $wrapperContents
        );
        file_put_contents($wrapperPath, $wrapperContents);
        chmod($wrapperPath, 0755);

        return [$pharPath, $wrapperPath];
    }

    private function downloadLatestPiePhar(string $targetPath): void
    {
        [$url, $filename] = Downloader::getLatestGithubRelease('pie', [
            'repo' => 'php/pie',
            'match' => 'pie\.phar',
            'prefer-stable' => true,
        ]);

        Downloader::downloadFile(
            name: 'pie',
            url: $url,
            filename: $filename,
            move_path: null,
            download_as: SPC_DOWNLOAD_PACKAGE,
            headers: ['Accept: application/octet-stream'],
            hooks: [[CurlHook::class, 'setupGithubToken']]
        );

        $downloaded = DOWNLOAD_PATH . '/' . $filename;
        if (!file_exists($downloaded)) {
            throw new \RuntimeException('PIE download did not produce expected file: ' . $downloaded);
        }

        if ($downloaded !== $targetPath && !@copy($downloaded, $targetPath)) {
            throw new \RuntimeException('Failed to stage pie.phar to build directory.');
        }
    }
}
