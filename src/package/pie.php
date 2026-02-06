<?php

namespace staticphp\package;

use RuntimeException;
use SPC\store\CurlHook;
use SPC\store\Downloader;
use staticphp\package;
use staticphp\step\CreatePackages;
use staticphp\util\TwigRenderer;
use Symfony\Component\Process\Process;

class pie implements package
{
    public function getName(): string
    {
        // Return pie with the suffix (e.g., "pie-zts", "pie-zts8.5", "pie-zts85")
        return 'pie' . getBinarySuffix();
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
        $proc->setTimeout(2);
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

        throw new RuntimeException('Unable to detect PIE version from output: ' . trim($output));
    }
    public function getFpmConfig(): array
    {
        [$pharSource, $wrapperSource] = $this->prepareArtifacts();

        $prefix = CreatePackages::getPrefix();

        // Get versioned conflicts for pie packages (pie-zts8.0, pie-zts8.1, etc.)
        // Replace the 'php' prefix from conflicts with 'pie'
        $phpConflicts = CreatePackages::getVersionedConflicts('');
        $versionedConflicts = [];
        foreach ($phpConflicts as $conflict) {
            // Replace 'php' with 'pie' (e.g., php-zts8.5 -> pie-zts8.5, php-nts85 -> pie-nts85)
            $versionedConflicts[] = str_replace('php', 'pie', $conflict);
        }

        return [
            'depends' => [
                $prefix . '-cli',
                $prefix . '-devel',
            ],
            'provides' => [],
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

    public function getDescription(): string
    {
        return 'PHP Installer for Extensions (PIE)';
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

        // Render the pie wrapper script using Twig template
        $binarySuffix = getBinarySuffix();
        $wrapperPath = TEMP_DIR . '/pie' . $binarySuffix;

        $wrapperContents = TwigRenderer::render('pie-wrapper.twig', [
            'binary_suffix' => $binarySuffix,
            'sharedir' => getSharedir(),
        ]);

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
            throw new RuntimeException('PIE download did not produce expected file: ' . $downloaded);
        }

        if ($downloaded !== $targetPath && !@copy($downloaded, $targetPath)) {
            throw new RuntimeException('Failed to stage pie.phar to build directory.');
        }
    }
}
