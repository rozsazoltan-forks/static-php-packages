<?php

namespace staticphp\package;

use staticphp\package;
use staticphp\step\CreatePackages;
use Symfony\Component\Process\Process;

class frankenphp implements package
{
    public function getName(): string
    {
        // RPM packages use frankenphp (unversioned, for module system)
        // DEB/APK packages use versioned frankenphp8.3 or frankenphp83
        $prefix = CreatePackages::getPrefix();

        // Extract version from prefix (e.g., "php-zts8.3" -> "8.3", "php-zts" -> "")
        if (preg_match('/php-zts(\d+\.?\d*)/', $prefix, $matches)) {
            return 'frankenphp' . $matches[1];
        }
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
     * Get list of versioned frankenphp packages to conflict/replace with
     * For RPM packages, returns empty array (RPM uses module system instead)
     */
    private function getVersionedConflicts(): array
    {
        // Get the conflicts list from CreatePackages using the franken prefix
        $phpConflicts = CreatePackages::getVersionedConflicts('');

        // Transform php-zts8.3 conflicts to frankenphp8.3 conflicts
        $conflicts = [];
        foreach ($phpConflicts as $conflict) {
            $conflicts[] = str_replace('php-zts', 'frankenphp', $conflict);
        }

        return $conflicts;
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
        if (in_array('apk', $packageTypes, true)) {
            $this->createApkPackage($architecture, $binaryDependencies, $iterationOverride);
        }
    }

    /**
     * Create RPM package for FrankenPHP
     */
    public function createRpmPackage(string $architecture, array $binaryDependencies, ?string $iterationOverride = null): void
    {
        CreatePackages::setCurrentPackageType('rpm');
        echo "Creating RPM package for FrankenPHP...\n";

        $packageFolder = DIST_PATH . '/frankenphp/package';
        $phpVersion = str_replace('.', '', SPP_PHP_VERSION);
        $phpEmbedName = 'libphp-zts-' . $phpVersion . '.so';

        $ldLibraryPath = 'LD_LIBRARY_PATH=' . BUILD_LIB_PATH;
        [, $output] = shell()->execWithResult($ldLibraryPath . ' ' . BUILD_BIN_PATH . '/frankenphp --version');
        $output = implode("\n", $output);
        preg_match('/FrankenPHP v(\d+\.\d+\.\d+)/', $output, $matches);
        $version = $matches[1];

        $name = $this->getName();

        $computed = (string)$this->getNextIteration($name, $version, $architecture);
        $iteration = $iterationOverride ?? $computed;

        $versionedConflicts = $this->getVersionedConflicts();

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
            '--provides', 'frankenphp',
        ];

        foreach ($versionedConflicts as $conflict) {
            $fpmArgs[] = '--conflicts';
            $fpmArgs[] = $conflict;
            $fpmArgs[] = '--replaces';
            $fpmArgs[] = $conflict;
        }

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
        CreatePackages::setCurrentPackageType('deb');
        echo "Creating DEB package for FrankenPHP...\n";

        $packageFolder = DIST_PATH . '/frankenphp/package';
        $phpVersion = str_replace('.', '', SPP_PHP_VERSION);
        $phpEmbedName = 'libphp-zts-' . $phpVersion . '.so';

        $ldLibraryPath = 'LD_LIBRARY_PATH=' . BUILD_LIB_PATH;
        [, $output] = shell()->execWithResult($ldLibraryPath . ' ' . BUILD_BIN_PATH . '/frankenphp --version');
        $output = implode("\n", $output);
        preg_match('/FrankenPHP v(\d+\.\d+\.\d+)/', $output, $matches);
        $version = $matches[1];

        $name = $this->getName();

        $computed = (string)$this->getNextIteration($name, $version, $architecture);
        $iteration = $iterationOverride ?? $computed;
        $debIteration = $iteration;

        $versionedConflicts = $this->getVersionedConflicts();

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
            '--provides', 'frankenphp',
        ];

        foreach ($versionedConflicts as $conflict) {
            $fpmArgs[] = '--conflicts';
            $fpmArgs[] = $conflict;
            $fpmArgs[] = '--replaces';
            $fpmArgs[] = $conflict;
        }

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

        // Determine the FrankenPHP suffix (just version, not -zts prefix)
        // Extract version from package name: frankenphp8.5 or frankenphp85
        $prefix = CreatePackages::getPrefix();
        $frankenphpSuffix = '';
        if (preg_match('/php-zts(\d+\.?\d*)/', $prefix, $matches)) {
            $frankenphpSuffix = $matches[1];
        }

        $fpmArgs = [...$fpmArgs, ...[
            '--depends', $phpEmbedName,
            '--after-install', "{$packageFolder}/debian/postinst.sh",
            '--before-remove', "{$packageFolder}/debian/prerm.sh",
            '--after-remove', "{$packageFolder}/debian/postrm.sh",
            '--iteration', $debIteration,
            '--rpm-user', 'frankenphp',
            '--rpm-group', 'frankenphp',
            BUILD_BIN_PATH . '/frankenphp=/usr/bin/frankenphp' . $frankenphpSuffix,
            "{$packageFolder}/debian/frankenphp.service=/usr/lib/systemd/system/frankenphp{$frankenphpSuffix}.service",
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
     * Create APK package for FrankenPHP
     */
    public function createApkPackage(string $architecture, array $binaryDependencies, ?string $iterationOverride = null): void
    {
        CreatePackages::setCurrentPackageType('apk');
        echo "Creating APK package for FrankenPHP using nfpm...\n";

        $packageFolder = DIST_PATH . '/frankenphp/package';
        $phpVersion = str_replace('.', '', SPP_PHP_VERSION);
        $phpEmbedName = 'libphp-zts-' . $phpVersion . '.so';

        $ldLibraryPath = 'LD_LIBRARY_PATH=' . BUILD_LIB_PATH;
        [, $output] = shell()->execWithResult($ldLibraryPath . ' ' . BUILD_BIN_PATH . '/frankenphp --version');
        $output = implode("\n", $output);
        preg_match('/FrankenPHP v(\d+\.\d+\.\d+)/', $output, $matches);
        $version = $matches[1];

        $name = $this->getName();

        $computed = (string)$this->getNextIteration($name, $version, $architecture);
        $iteration = $iterationOverride ?? $computed;

        $versionedConflicts = $this->getVersionedConflicts();

        // Build nfpm config
        $nfpmConfig = [
            'name' => $name,
            'arch' => $architecture,
            'platform' => 'linux',
            'version' => $version,
            'release' => $iteration,
            'section' => 'default',
            'priority' => 'optional',
            'maintainer' => 'Marc Henderkes <apks@henderkes.com>',
            'description' => "FrankenPHP - Modern PHP application server",
            'vendor' => 'Marc Henderkes',
            'homepage' => 'https://apks.henderkes.com',
            'license' => $this->getLicense(),
            'apk' => [
                'signature' => [
                    'key_name' => CreatePackages::getPrefix(),
                ],
            ],
        ];

        // Build dependencies
        $depends = [$phpEmbedName];

        // Alpine library dependencies
        $alpineLibMap = [
            'ld-linux-x86-64.so.2' => 'musl',
            'ld-linux-aarch64.so.1' => 'musl',
            'libc.so.6' => 'musl',
            'libm.so.6' => 'musl',
            'libpthread.so.0' => 'musl',
            'libutil.so.1' => 'musl',
            'libdl.so.2' => 'musl',
            'librt.so.1' => 'musl',
            'libresolv.so.2' => 'musl',
            'libgcc_s.so.1' => 'libgcc',
            'libstdc++.so.6' => 'libstdc++',
        ];

        foreach ($binaryDependencies as $lib => $ver) {
            if (isset($alpineLibMap[$lib])) {
                $packageName = $alpineLibMap[$lib];
            } else {
                $packageName = preg_replace('/\.so(\.\d+)*$/', '', $lib);
            }
            $numericVersion = preg_replace('/[^0-9.]/', '', $ver);
            $depends[] = "{$packageName}>={$numericVersion}";
        }

        $nfpmConfig['depends'] = $depends;
        $nfpmConfig['provides'] = ['frankenphp'];
        $nfpmConfig['replaces'] = $versionedConflicts;
        $nfpmConfig['conflicts'] = $versionedConflicts;

        // Determine the FrankenPHP suffix
        $prefix = CreatePackages::getPrefix();
        $frankenphpSuffix = '';
        if (preg_match('/php-zts(\d+\.?\d*)/', $prefix, $matches)) {
            $frankenphpSuffix = $matches[1];
        }

        $alpineFolder = BASE_PATH . '/src/package/frankenphp';

        // Build contents
        $contents = [
            [
                'src' => BUILD_BIN_PATH . '/frankenphp',
                'dst' => '/usr/bin/frankenphp' . $frankenphpSuffix,
            ],
            [
                'src' => "{$alpineFolder}/alpine/frankenphp.openrc",
                'dst' => "/etc/init.d/frankenphp{$frankenphpSuffix}",
            ],
            [
                'src' => "{$packageFolder}/Caddyfile",
                'dst' => '/etc/frankenphp/Caddyfile',
                'type' => 'config',
            ],
            [
                'src' => "{$packageFolder}/content/",
                'dst' => '/usr/share/frankenphp/',
            ],
            [
                'dst' => '/var/lib/frankenphp',
                'type' => 'dir',
            ],
            [
                'dst' => '/etc/frankenphp/Caddyfile.d',
                'type' => 'dir',
            ],
        ];

        $nfpmConfig['contents'] = $contents;

        // Add scripts
        $nfpmConfig['scripts'] = [
            'postinstall' => "{$alpineFolder}/alpine/post-install.sh",
            'preremove' => "{$alpineFolder}/alpine/pre-deinstall.sh",
            'postremove' => "{$alpineFolder}/alpine/post-deinstall.sh",
        ];

        // Write nfpm config
        $nfpmConfigFile = TEMP_DIR . "/nfpm-{$name}.yaml";
        if (!yaml_emit_file($nfpmConfigFile, $nfpmConfig, YAML_UTF8_ENCODING)) {
            throw new \RuntimeException("Failed to write YAML file: {$nfpmConfigFile}");
        }

        echo "nfpm config written to: {$nfpmConfigFile}\n";

        // Run nfpm
        $outputFile = DIST_APK_PATH . "/{$name}-{$version}-r{$iteration}.{$architecture}.apk";
        $nfpmProcess = new Process([
            'nfpm', 'package',
            '--config', $nfpmConfigFile,
            '--packager', 'apk',
            '--target', $outputFile
        ]);
        $nfpmProcess->setTimeout(null);
        $nfpmProcess->run(function ($type, $buffer) {
            echo $buffer;
        });

        if (!$nfpmProcess->isSuccessful()) {
            echo "nfpm config file contents:\n";
            echo file_get_contents($nfpmConfigFile);
            throw new \RuntimeException("nfpm package creation failed: " . $nfpmProcess->getErrorOutput());
        }

        @unlink($nfpmConfigFile);

        echo "APK package created: {$outputFile}\n";

        // Create FrankenPHP debuginfo package if debug file exists
        $frankenDbg = BUILD_ROOT_PATH . '/debug/frankenphp.debug';
        if (file_exists($frankenDbg)) {
            $this->createApkDebuginfo($name, $version, $iteration, $architecture, $frankenDbg, $frankenphpSuffix);
        }
    }

    private function createApkDebuginfo(string $name, string $version, string $iteration, string $architecture, string $frankenDbg, string $frankenphpSuffix): void
    {
        $dbgName = $name . '-debuginfo';
        
        $nfpmConfig = [
            'name' => $dbgName,
            'arch' => $architecture,
            'platform' => 'linux',
            'version' => $version,
            'release' => $iteration,
            'section' => 'default',
            'priority' => 'optional',
            'maintainer' => 'Marc Henderkes <apks@henderkes.com>',
            'description' => "Debug symbols for FrankenPHP",
            'vendor' => 'Marc Henderkes',
            'homepage' => 'https://apks.henderkes.com',
            'license' => $this->getLicense(),
            'depends' => [sprintf('%s=%s-r%s', $name, $version, $iteration)],
            'contents' => [
                [
                    'src' => $frankenDbg,
                    'dst' => '/usr/lib/debug/usr/bin/frankenphp' . $frankenphpSuffix . '.debug',
                ],
            ],
        ];

        $nfpmConfigFile = TEMP_DIR . "/nfpm-{$dbgName}.yaml";
        if (!yaml_emit_file($nfpmConfigFile, $nfpmConfig, YAML_UTF8_ENCODING)) {
            throw new \RuntimeException("Failed to write YAML file: {$nfpmConfigFile}");
        }

        $outputFile = DIST_APK_PATH . "/{$dbgName}-{$version}-r{$iteration}.{$architecture}.apk";
        $dbgProcess = new Process([
            'nfpm', 'package',
            '--config', $nfpmConfigFile,
            '--packager', 'apk',
            '--target', $outputFile
        ]);
        $dbgProcess->setTimeout(null);
        $dbgProcess->run(function ($type, $buffer) {
            echo $buffer;
        });

        if (!$dbgProcess->isSuccessful()) {
            throw new \RuntimeException("nfpm debuginfo package creation failed: " . $dbgProcess->getErrorOutput());
        }

        @unlink($nfpmConfigFile);
        echo "APK debuginfo package created: {$outputFile}\n";
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
