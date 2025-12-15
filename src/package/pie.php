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

        $proc = new Process(['php', $pharSource, '-V']);
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

        throw new \RuntimeException('Unable to detect PIE version from output: ' . trim($output));
    }
    public function getFpmConfig(): array
    {
        [$pharSource, $wrapperSource] = $this->prepareArtifacts();

        $prefix = CreatePackages::getPrefix();
        $versionedConflicts = [];

        // Generate conflicts for pie-php-zts8.0, pie-php-zts8.1, etc.
        $phpVersion = SPP_PHP_VERSION;
        if (preg_match('/^(\d+)\.(\d+)/', $phpVersion, $matches)) {
            $currentMajor = (int)$matches[1];
            $currentMinor = (int)$matches[2];
            for ($minor = 0; $minor <= 9; $minor++) {
                if ($currentMajor === 8 && $minor === $currentMinor) {
                    continue;
                }
                $versionedConflicts[] = "pie-php-zts{$currentMajor}.{$minor}";
            }
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
                $pharSource => '/usr/share/php-zts/pie.phar',
                $wrapperSource => '/usr/bin/pie-zts',
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

    private function prepareArtifacts(): array
    {
        $pharPath = DOWNLOAD_PATH . '/pie.phar';
        if (!file_exists($pharPath)) {
            $this->downloadLatestPiePhar($pharPath);
        }

        $wrapperPath = INI_PATH . '/pie-zts';
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
