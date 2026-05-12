<?php

namespace staticphp\package;

use RuntimeException;
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
        // Resolve the asset URL for `pie.phar` from the latest stable GitHub release.
        $headers = self::githubAuthHeaders();
        $body = default_shell()->executeCurl(
            'https://api.github.com/repos/php/pie/releases/latest',
            headers: $headers,
        );
        $data = json_decode((string) $body, true);
        if (!is_array($data) || empty($data['assets']) || !is_array($data['assets'])) {
            throw new RuntimeException('PIE: failed to fetch latest release metadata from GitHub.');
        }
        $assetUrl = null;
        foreach ($data['assets'] as $asset) {
            if (isset($asset['name'], $asset['url']) && preg_match('/^pie\.phar$/', $asset['name'])) {
                $assetUrl = $asset['url'];
                break;
            }
        }
        if ($assetUrl === null) {
            throw new RuntimeException('PIE: no pie.phar asset found in latest release.');
        }

        // GitHub's asset endpoints redirect to a signed S3 URL when the
        // `application/octet-stream` Accept header is used.
        default_shell()->executeCurlDownload(
            $assetUrl,
            $targetPath,
            headers: array_merge($headers, ['Accept: application/octet-stream']),
        );

        if (!file_exists($targetPath)) {
            throw new RuntimeException('PIE download did not produce expected file: ' . $targetPath);
        }
    }

    /** @return list<string> */
    private static function githubAuthHeaders(): array
    {
        $token = getenv('GITHUB_TOKEN') ?: getenv('GH_TOKEN');
        if (!is_string($token) || $token === '') {
            return [];
        }
        return [
            'Authorization: Bearer ' . $token,
            'X-GitHub-Api-Version: 2022-11-28',
        ];
    }
}
