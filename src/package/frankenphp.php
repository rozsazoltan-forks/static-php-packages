<?php

namespace staticphp\package;

use staticphp\package;
use staticphp\step\CreatePackages;
use Symfony\Component\Process\Process;

class frankenphp implements package
{
    public function getName(): string
    {
        return 'frankenphp';
    }

    public function getFpmConfig(): array
    {
        return [];
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
        return 'MIT';
    }

    /**
     * Create FrankenPHP packages (both RPM and DEB)
     */
    public function createPackages(array $packageTypes, array $binaryDependencies, ?string $iterationOverride = null): void
    {
        echo "Creating FrankenPHP package\n";

        [, $architecture] = $this->getPhpVersionAndArchitecture();

        $this->prepareFrankenPhpRepository();

        if (in_array('rpm', $packageTypes, true)) {
            $this->createRpmPackage($architecture, $binaryDependencies, $iterationOverride);
        }
        if (in_array('deb', $packageTypes, true)) {
            $this->createDebPackage($architecture, $binaryDependencies, $iterationOverride);
        }
    }

    /**
     * Create RPM package for FrankenPHP
     */
    public function createRpmPackage(string $architecture, array $binaryDependencies, ?string $iterationOverride = null): void
    {
        echo "Creating RPM package for FrankenPHP...\n";

        $packageFolder = DIST_PATH . '/frankenphp/package';
        $phpVersion = str_replace('.', '', SPP_PHP_VERSION);
        $phpEmbedName = 'lib' . CreatePackages::getPrefix() . '-' . $phpVersion . '.so';

        $ldLibraryPath = 'LD_LIBRARY_PATH=' . BUILD_LIB_PATH;
        [, $output] = shell()->execWithResult($ldLibraryPath . ' ' . BUILD_BIN_PATH . '/frankenphp --version');
        $output = implode("\n", $output);
        preg_match('/FrankenPHP v(\d+\.\d+\.\d+)/', $output, $matches);
        $latestTag = $matches[1];
        $version = $latestTag . '_' . $phpVersion;

        $name = "frankenphp";

        $computed = (string)$this->getNextIteration($name, $version, $architecture);
        $iteration = $iterationOverride ?? $computed;

        $fpmArgs = [
            'fpm',
            '-s', 'dir',
            '-t', 'rpm',
            '--rpm-compression', 'xz',
            '-p', DIST_RPM_PATH,
            '-n', $name,
            '-v', $version,
            '--license', $this->getLicense(),
            '--config-files', '/etc/frankenphp/Caddyfile',
        ];

        foreach ($binaryDependencies as $lib => $dependencyVersion) {
            $fpmArgs[] = '--depends';
            $fpmArgs[] = "$lib({$dependencyVersion})(64bit)";
        }

        if (!is_dir("{$packageFolder}/empty/") && !mkdir("{$packageFolder}/empty/", 0755, true) && !is_dir("{$packageFolder}/empty/")) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', "{$packageFolder}/empty/"));
        }

        $fpmArgs = [...$fpmArgs, ...[
            '--depends', "$phpEmbedName",
            '--before-install', "{$packageFolder}/rhel/preinstall.sh",
            '--after-install', "{$packageFolder}/rhel/postinstall.sh",
            '--before-remove', "{$packageFolder}/rhel/preuninstall.sh",
            '--after-remove', "{$packageFolder}/rhel/postuninstall.sh",
            '--iteration', $iteration,
            '--rpm-user', 'frankenphp',
            '--rpm-group', 'frankenphp',
            '--config-files', '/etc/frankenphp/Caddyfile',
            '--config-files', '/etc/frankenphp/Caddyfile.d',
            BUILD_BIN_PATH . '/frankenphp=/usr/bin/frankenphp',
            "{$packageFolder}/rhel/frankenphp.service=/usr/lib/systemd/system/frankenphp.service",
            "{$packageFolder}/Caddyfile=/etc/frankenphp/Caddyfile",
            "{$packageFolder}/content/=/usr/share/frankenphp",
            "{$packageFolder}/empty/=/var/lib/frankenphp",
            "{$packageFolder}/empty/=/etc/frankenphp/Caddyfile.d",
        ]];

        $rpmProcess = new Process($fpmArgs);
        $rpmProcess->setTimeout(null);
        $rpmProcess->run(function ($type, $buffer) {
            echo $buffer;
        });

        echo "RPM package created: " . DIST_RPM_PATH . "/{$name}-{$version}-{$iteration}.{$architecture}.rpm\n";

        // Create FrankenPHP debuginfo package if debug file exists
        $frankenDbg = BUILD_ROOT_PATH . '/debug/frankenphp.debug';
        if (file_exists($frankenDbg)) {
            $dbgArgs = [
                'fpm',
                '-s', 'dir',
                '-t', 'rpm',
                '--rpm-compression', 'xz',
                '-p', DIST_RPM_PATH,
                '-n', $name . '-debuginfo',
                '-v', $version,
                '--iteration', $iteration,
                '--architecture', $architecture,
                '--license', $this->getLicense(),
                '--depends', sprintf('%s = %s-%s', $name, $version, $iteration),
                $frankenDbg . '=/usr/lib/debug/usr/bin/frankenphp.debug',
            ];
            $dbgProcess = new Process($dbgArgs);
            $dbgProcess->setTimeout(null);
            $dbgProcess->run(function ($type, $buffer) {
                echo $buffer;
            });
            if (!$dbgProcess->isSuccessful()) {
                throw new \RuntimeException("RPM debuginfo package creation failed: " . $dbgProcess->getErrorOutput());
            }
        }
    }

    /**
     * Create DEB package for FrankenPHP
     */
    public function createDebPackage(string $architecture, array $binaryDependencies, ?string $iterationOverride = null): void
    {
        echo "Creating DEB package for FrankenPHP...\n";

        $packageFolder = DIST_PATH . '/frankenphp/package';
        $phpVersion = str_replace('.', '', SPP_PHP_VERSION);
        $phpEmbedName = 'lib' . CreatePackages::getPrefix() . '-' . $phpVersion . '.so';

        $ldLibraryPath = 'LD_LIBRARY_PATH=' . BUILD_LIB_PATH;
        [, $output] = shell()->execWithResult($ldLibraryPath . ' ' . BUILD_BIN_PATH . '/frankenphp --version');
        $output = implode("\n", $output);
        preg_match('/FrankenPHP v(\d+\.\d+\.\d+)/', $output, $matches);
        $version = $matches[1];

        $name = "frankenphp";

        $computed = (string)$this->getNextIteration($name, $version, $architecture);
        $iteration = $iterationOverride ?? $computed;
        $debIteration = $iteration;

        $fpmArgs = [
            'fpm',
            '-s', 'dir',
            '-t', 'deb',
            '--deb-compression', 'xz',
            '-p', DIST_DEB_PATH,
            '-n', $name,
            '-v', $version,
            '--license', $this->getLicense(),
            '--config-files', '/etc/frankenphp/Caddyfile',
        ];

        $systemLibraryMap = [
            'ld-linux-x86-64.so.2' => 'libc6',
            'ld-linux-aarch64.so.1' => 'libc6',
            'libm.so.6' => 'libc6',
            'libc.so.6' => 'libc6',
            'libpthread.so.0' => 'libc6',
            'libutil.so.1' => 'libc6',
            'libdl.so.2' => 'libc6',
            'librt.so.1' => 'libc6',
            'libresolv.so.2' => 'libc6',
            'libgcc_s.so.1' => 'libgcc-s1',
            'libstdc++.so.6' => 'libstdc++6',
        ];
        foreach ($binaryDependencies as $lib => $ver) {
            if (isset($systemLibraryMap[$lib])) {
                // Use mapped name for system libraries
                $packageName = $systemLibraryMap[$lib];
            }
            else {
                // For other libraries, remove .so suffix
                $packageName = preg_replace('/\.so(\.\d+)?$/', '', $lib);
            }

            $numericVersion = preg_replace('/[^0-9.]/', '', $ver);
            $fpmArgs[] = '--depends';
            $fpmArgs[] = "{$packageName} (>= {$numericVersion})";
        }

        if (!is_dir("{$packageFolder}/empty/") && !mkdir("{$packageFolder}/empty/", 0755, true) && !is_dir("{$packageFolder}/empty/")) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', "{$packageFolder}/empty/"));
        }

        $fpmArgs = [...$fpmArgs, ...[
            '--depends', $phpEmbedName,
            '--after-install', "{$packageFolder}/debian/postinst.sh",
            '--before-remove', "{$packageFolder}/debian/prerm.sh",
            '--after-remove', "{$packageFolder}/debian/postrm.sh",
            '--iteration', $debIteration,
            '--rpm-user', 'frankenphp',
            '--rpm-group', 'frankenphp',
            BUILD_BIN_PATH . '/frankenphp=/usr/bin/frankenphp',
            "{$packageFolder}/debian/frankenphp.service=/usr/lib/systemd/system/frankenphp.service",
            "{$packageFolder}/Caddyfile=/etc/frankenphp/Caddyfile",
            "{$packageFolder}/content/=/usr/share/frankenphp",
            "{$packageFolder}/empty/=/var/lib/frankenphp"
        ]];

        $rpmProcess = new Process($fpmArgs);
        $rpmProcess->setTimeout(null);
        $rpmProcess->run(function ($type, $buffer) {
            echo $buffer;
        });

        echo "DEB package created: " . DIST_DEB_PATH . "/{$name}-{$version}-{$debIteration}.{$architecture}.deb\n";

        // Create FrankenPHP debuginfo package if debug file exists
        $frankenDbg = BUILD_ROOT_PATH . '/debug/frankenphp.debug';
        if (file_exists($frankenDbg)) {
            $dbgArgs = [
                'fpm',
                '-s', 'dir',
                '-t', 'deb',
                '--deb-compression', 'xz',
                '-p', DIST_DEB_PATH,
                '-n', $name . '-debuginfo',
                '-v', $version,
                '--iteration', $debIteration,
                '--architecture', $architecture,
                '--license', $this->getLicense(),
                '--depends', sprintf('%s (= %s-%s)', $name, $version, $debIteration),
                $frankenDbg . '=/usr/lib/debug/usr/bin/frankenphp.debug',
            ];
            $dbgProcess = new Process($dbgArgs);
            $dbgProcess->setTimeout(null);
            $dbgProcess->run(function ($type, $buffer) {
                echo $buffer;
            });
            if (!$dbgProcess->isSuccessful()) {
                throw new \RuntimeException("DEB debuginfo package creation failed: " . $dbgProcess->getErrorOutput());
            }
        }
    }

    /**
     * Prepare FrankenPHP repository by cloning or updating
     */
    private function prepareFrankenPhpRepository(): string
    {
        $repoUrl = 'https://github.com/php/frankenphp.git';
        $targetPath = DIST_PATH . '/frankenphp';

        $tagProcess = new Process([
            'bash', '-c',
            "git ls-remote --tags $repoUrl | grep -o 'refs/tags/[^{}]*$' | sed 's#refs/tags/##' | sort -V | tail -n1"
        ]);
        $tagProcess->run();
        if (!$tagProcess->isSuccessful()) {
            throw new \RuntimeException("Failed to fetch tags: " . $tagProcess->getErrorOutput());
        }
        $latestTag = trim($tagProcess->getOutput());

        if (!is_dir($targetPath . '/.git')) {
            echo "Cloning FrankenPHP into DIST_PATH...\n";
            $clone = new Process(['git', 'clone', $repoUrl, $targetPath]);
            $clone->run();
            if (!$clone->isSuccessful()) {
                throw new \RuntimeException("Git clone failed: " . $clone->getErrorOutput());
            }
        }
        else {
            echo "FrankenPHP already exists, fetching tags...\n";
            $fetch = new Process(['git', 'fetch', '--tags'], cwd: $targetPath);
            $fetch->run();
            if (!$fetch->isSuccessful()) {
                throw new \RuntimeException("Git fetch failed: " . $fetch->getErrorOutput());
            }
        }

        $checkout = new Process(['git', 'checkout', $latestTag], cwd: $targetPath);
        $checkout->run();
        if (!$checkout->isSuccessful()) {
            throw new \RuntimeException("Git checkout failed: " . $checkout->getErrorOutput());
        }

        return $latestTag;
    }

    /**
     * Get PHP version and architecture
     */
    private function getPhpVersionAndArchitecture(): array
    {
        $basePhpVersion = SPP_PHP_VERSION;
        $phpBinary = BUILD_BIN_PATH . '/php';

        if (!file_exists($phpBinary)) {
            throw new \RuntimeException("Warning: PHP binary not found at {$phpBinary}, using base PHP version: {$basePhpVersion}");
        }
        $versionProcess = new Process([$phpBinary, '-r', 'echo PHP_VERSION;']);
        $versionProcess->run();
        $detectedVersion = trim($versionProcess->getOutput());

        if (!empty($detectedVersion)) {
            $fullPhpVersion = $detectedVersion;
            echo "Detected full PHP version from binary: {$fullPhpVersion}\n";
        }
        else {
            throw new \RuntimeException("Warning: Could not detect PHP version from binary using base version: {$basePhpVersion}");
        }

        $archProcess = new Process(['uname', '-m']);
        $archProcess->run();
        $architecture = trim($archProcess->getOutput());

        if (empty($architecture)) {
            $archProcess = new Process(['arch']);
            $archProcess->run();
            $architecture = trim($archProcess->getOutput());

            if (empty($architecture)) {
                echo "Warning: Could not determine architecture, using x86_64 as fallback\n";
                $architecture = 'x86_64';
            }
        }

        return [$fullPhpVersion, $architecture];
    }

    /**
     * Get next iteration number for package
     */
    private function getNextIteration(string $name, string $version, string $architecture): int
    {
        $maxIteration = 0;

        $rpmPattern = DIST_RPM_PATH . "/{$name}-{$version}-*.{$architecture}.rpm";
        $rpmFiles = glob($rpmPattern);

        foreach ($rpmFiles as $file) {
            if (preg_match("/{$name}-{$version}-(\d+)\.{$architecture}\.rpm$/", $file, $matches)) {
                $iteration = (int)$matches[1];
                $maxIteration = max($maxIteration, $iteration);
            }
        }

        $debPattern = DIST_DEB_PATH . "/{$name}_{$version}-*_{$architecture}.deb";
        $debFiles = glob($debPattern);

        foreach ($debFiles as $file) {
            if (preg_match("/{$name}_{$version}-(\d+)_{$architecture}\.deb$/", $file, $matches)) {
                $iteration = (int)$matches[1];
                $maxIteration = max($maxIteration, $iteration);
            }
        }

        return $maxIteration + 1;
    }
}
