<?php

namespace staticphp\step;

use SPC\store\Config;
use staticphp\extension;
use Symfony\Component\Process\Process;
use staticphp\CraftConfig;

class CreatePackages
{
    private static array $versionArch = [];
    private static $extensions = [];
    private static $sharedExtensions = [];
    private static $sapis = [];
    private static $binaryDependencies = [];
    private static $packageTypes = [];
    private static ?string $iterationOverride = null;
    private static ?string $currentPackageType = null;

    public static function setCurrentPackageType(?string $type): void
    {
        self::$currentPackageType = $type;
    }

    public static function run($packageNames = null, string $packageTypes = 'rpm,deb', string $phpVersion = '8.4', ?string $iteration = null): true
    {
        self::loadConfig();

        define('DOWNLOAD_PATH', BUILD_ROOT_PATH . '/download');
        @mkdir(DOWNLOAD_PATH, 0755, true);

        $phpBinary = BUILD_BIN_PATH . '/php';
        self::$binaryDependencies = self::getBinaryDependencies($phpBinary);

        self::$packageTypes = explode(',', strtolower($packageTypes));
        self::$iterationOverride = $iteration !== null && $iteration !== '' ? (string)$iteration : null;

        if ($packageNames !== null) {
            if (is_string($packageNames)) {
                $packageNames = [$packageNames];
            }

            foreach ($packageNames as $packageName) {
                echo "Building package: {$packageName}\n";

                if (in_array($packageName, self::$sapis, true)) {
                    self::createSapiPackage($packageName);
                }
                elseif ($packageName === 'devel') {
                    self::createSapiPackage($packageName);
                }
                elseif (in_array($packageName, self::$sharedExtensions)) {
                    self::createExtensionPackage($packageName);
                }
                else {
                    $genericClass = "\\staticphp\\package\\{$packageName}";
                    if (class_exists($genericClass)) {
                        self::createGenericPackage($packageName);
                    }
                    else {
                        echo "Warning: Package {$packageName} not found in configuration.\n";
                    }
                }
            }
        }
        else {
            self::createSapiPackages();
            self::createSapiPackage('devel');
            self::createGenericPackage('pie');
            self::createExtensionPackages();
        }

        echo "Package creation completed.\n";
        return true;
    }

    /**
     * Create a generic package defined in src/package/{name}.php implementing staticphp\package
     */
    private static function createGenericPackage(string $name): void
    {
        $packageClass = "\\staticphp\\package\\{$name}";
        if (!class_exists($packageClass)) {
            echo "Warning: Package class not found: {$name}\n";
            return;
        }

        [$phpVersion, $architecture] = self::getPhpVersionAndArchitecture();

        // Allow generic packages to define their own version (e.g., pie.phar version)
        $pkgVersion = $phpVersion;
        $pkg = new $packageClass();
        if (method_exists($pkg, 'getVersion')) {
            $pkgVersion = $pkg->getVersion();
        }

        $package = $pkg ?? new $packageClass();

        $computed = (string)self::getNextIteration($package->getName(), $pkgVersion, $architecture);
        $iteration = self::$iterationOverride ?? $computed;

        self::createPackageWithFpm($package, $pkgVersion, $architecture, $iteration);

        $dbgConfig = $package->getDebuginfoFpmConfig();
        if (is_array($dbgConfig) && !empty($dbgConfig['files'])) {
            self::createPackageWithFpm($package, $pkgVersion, $architecture, $iteration, true);
        }
    }

    private static function loadConfig(): void
    {
        echo "Loading configuration from Twig template...\n";

        $craftConfig = CraftConfig::getInstance();

        self::$extensions = $craftConfig->getStaticExtensions();
        self::$sharedExtensions = $craftConfig->getSharedExtensions();
        self::$sapis = $craftConfig->getSapis();

        echo "Loaded configuration:\n";
        echo "- SAPIs: " . implode(', ', self::$sapis) . "\n";
        echo "- Extensions: " . implode(', ', self::$extensions) . "\n";
        echo "- Shared Extensions: " . implode(', ', self::$sharedExtensions) . "\n";
    }

    private static function createSapiPackages(): void
    {
        echo "Creating packages for SAPIs...\n";

        foreach (self::$sapis as $sapi) {
            self::createSapiPackage($sapi);
        }
    }

    private static function createSapiPackage(string $sapi): void
    {
        $packageClass = "\\staticphp\\package\\{$sapi}";

        if (!class_exists($packageClass)) {
            echo "Warning: Package class not found for SAPI: {$sapi}\n";
            return;
        }

        // FrankenPHP has a special package creation flow
        if ($sapi === 'frankenphp') {
            $package = new $packageClass();
            $package->createPackages(self::$packageTypes, self::$binaryDependencies, self::$iterationOverride);
            return;
        }

        [$phpVersion, $architecture] = self::getPhpVersionAndArchitecture();

        $package = new $packageClass();

        $computed = (string)self::getNextIteration($package->getName(), $phpVersion, $architecture);
        $iteration = self::$iterationOverride ?? $computed;

        self::createPackageWithFpm($package, $phpVersion, $architecture, $iteration);

        $dbgConfig = $package->getDebuginfoFpmConfig();
        if (is_array($dbgConfig) && !empty($dbgConfig['files'])) {
            self::createPackageWithFpm($package, $phpVersion, $architecture, $iteration, true);
        }
    }

    private static function createExtensionPackages(): void
    {
        echo "Creating packages for extensions...\n";

        foreach (self::$sharedExtensions as $extension) {
            if (Config::getExt($extension)['type'] === 'addon') {
                continue;
            }
            self::createExtensionPackage($extension);
        }
    }

    private static function createExtensionPackage(string $extension): void
    {
        [$phpVersion, $architecture] = self::getPhpVersionAndArchitecture();
        $extensionVersion = self::getExtensionVersion($extension, $phpVersion);

        $computed = (string)self::getNextIteration(self::getPrefix() . "-{$extension}", $extensionVersion, $architecture);
        $iteration = self::$iterationOverride ?? $computed;

        $package = new extension($extension);
        $packageClass = "\\staticphp\\package\\{$extension}";
        if (class_exists($packageClass)) {
            $package = new $packageClass($extension);
        }

        if (!file_exists(INI_PATH . '/extension/' . $extension . '.ini')) {
            echo "Warning: INI file for extension {$extension} not found, skipping package creation.\n";
            return;
        }

        self::createPackageWithFpm($package, $extensionVersion, $architecture, $iteration);

        $dbgConfig = $package->getDebuginfoFpmConfig();
        if (is_array($dbgConfig) && !empty($dbgConfig['files'])) {
            self::createPackageWithFpm($package, $extensionVersion, $architecture, $iteration, true);
        }
    }

    private static function getExtensionVersion(string $extension, string $phpVersion): string
    {
        $phpBinary = BUILD_BIN_PATH . '/php';

        if (!file_exists($phpBinary)) {
            throw new \RuntimeException("Warning: PHP binary not found at {$phpBinary}, using PHP version for extension {$extension}: {$phpVersion}");
        }

        $extensionClass = "\\staticphp\\package\\extension\\$extension";
        if (!class_exists($extensionClass)) {
            $extensionClass = extension::class;
        }
        $extensionC = new $extensionClass($extension);
        $dependencies = $extensionC->getExtensionDependencies($extension);
        $args = [
            '-n', '-d', 'error_reporting=0', '-d', 'extension_dir=' . BUILD_MODULES_PATH,
        ];
        foreach ($dependencies as $dependency) {
            $depExt = new extension($dependency);
            if ($depExt->isSharedExtension() && Config::getExt($dependency)['type'] !== 'addon') {
                $args[] = '-d';
                $args[] = "extension={$dependency}";
            }
        }
        $args[] = '-d';
        $args[] = "extension={$extension}";
        $versionProcess = new Process([$phpBinary, ...$args, '-r', "echo phpversion('{$extension}');"]);
        $versionProcess->run();
        $rawExtensionVersion = trim($versionProcess->getOutput());
        $rawExtensionVersion = trim(preg_replace('/^Warning:.*$/m', '', $rawExtensionVersion));

        // Parse the extension version preserving a possible pre-release suffix
        // Examples of inputs we want to support:
        //  - 1.2.3
        //  - 1.2.3RC2 / 1.2.3-rc2 / 1.2.3.rc2
        //  - 1.2.3beta1 / 1.2.3-alpha2 / 1.2.3dev
        // We must transform them to:
        //  - 1.2.3
        //  - 1.2.3~rc2 (tilde separator, lowercase suffix)
        //  - 1.2.3~beta1 / 1.2.3~alpha2 / 1.2.3~dev
        $extensionVersion = null;
        $suffix = null;
        if (preg_match('/(\d+\.\d+(?:\.\d+)?)(?:[.-]?((?:alpha|beta|rc|dev)\d*))?/i', $rawExtensionVersion, $m)) {
            $extensionVersion = $m[1];
            if (!empty($m[2])) {
                $suffix = strtolower($m[2]) . (isset($m[3]) ? $m[3] : '');
            }
        }
        // Fallback: try to extract just the numeric part if the above fails
        if ($extensionVersion === null && preg_match('/(\d+\.\d+(?:\.\d+)?)/', $rawExtensionVersion, $m2)) {
            $extensionVersion = $m2[1];
        }
        if ($extensionVersion !== null && $suffix) {
            $extensionVersion .= "~{$suffix}";
        }

        if (empty($extensionVersion)) {
            throw new \RuntimeException("Warning: Could not detect version for extension {$extension}");
        }

        echo "Detected version for extension {$extension}: {$extensionVersion}\n";

        return $extensionVersion;
    }

    private static function createPackageWithFpm(\staticphp\package $package, string $phpVersion, string $architecture, string $iteration, bool $isDebuginfo = false): void
    {
        if (in_array('rpm', self::$packageTypes, true)) {
            self::createRpmPackage($package, $phpVersion, $architecture, $iteration, $isDebuginfo);
        }

        if (in_array('deb', self::$packageTypes, true)) {
            self::createDebPackage($package, $phpVersion, $architecture, $iteration, $isDebuginfo);
        }

        if (in_array('apk', self::$packageTypes, true)) {
            self::createApkPackage($package, $phpVersion, $architecture, $iteration, $isDebuginfo);
        }
    }

    private static function createRpmPackage(\staticphp\package $package, string $phpVersion, string $architecture, string $iteration, bool $isDebuginfo = false): void
    {
        self::$currentPackageType = 'rpm';
        $name = $isDebuginfo ? $package->getName() . '-debuginfo' : $package->getName();
        $config = $isDebuginfo ? $package->getDebuginfoFpmConfig() : $package->getFpmConfig();
        $extraArgs = $isDebuginfo ? [] : $package->getFpmExtraArgs();

        echo "Creating RPM package for {$name}...\n";

        $fpmArgs = [...[
            'fpm',
            '-s', 'dir',
            '-t', 'rpm',
            '--rpm-compression', 'xz',
            '-p', DIST_RPM_PATH,
            '--name', $name,
            '--version', $phpVersion,
            '--iteration', $iteration,
            '--architecture', $architecture,
            '--description', "Static PHP Package for {$name}",
            '--license', $package->getLicense(),
            '--maintainer', 'Marc Henderkes <rpms@henderkes.com>',
            '--vendor', 'Marc Henderkes <rpms@henderkes.com>',
            '--url', 'rpms.henderkes.com',
        ], ...$extraArgs];

        // Ensure non-CLI packages depend on the same PHP major.minor as php-zts-cli (ignore iteration/patch)
        if ($name !== self::getPrefix() . '-cli') {
            [$fullPhpVersion] = self::getPhpVersionAndArchitecture();
            if (preg_match('/^(\d+)\.(\d+)/', $fullPhpVersion, $m)) {
                $maj = (int)$m[1];
                $min = (int)$m[2];
                $nextMin = $min + 1;
                $lowerBound = sprintf('%d.%d', $maj, $min);
                $upperBound = sprintf('%d.%d', $maj, $nextMin);
                // RPM range: >= X.Y and < X.(Y+1)
                $fpmArgs[] = '--depends';
                $fpmArgs[] = self::getPrefix() . "-cli >= {$lowerBound}";
                $fpmArgs[] = '--depends';
                $fpmArgs[] = self::getPrefix() . "-cli < {$upperBound}";
            }
        }

        if (str_ends_with($name, '-debuginfo')) {
            $base = preg_replace('/-debuginfo$/', '', $name);
            $fpmArgs[] = '--depends';
            $fpmArgs[] = sprintf('%s = %s-%s', $base, $phpVersion, $iteration);
        }

        if (isset($config['provides']) && is_array($config['provides'])) {
            foreach ($config['provides'] as $provide) {
                $fpmArgs[] = '--provides';
                $fpmArgs[] = "$provide = $phpVersion-$iteration";
                if (str_ends_with($provide, '.so')) {
                    $provide = str_replace('.so', '.so()(64bit)', $provide);
                    $fpmArgs[] = '--provides';
                    $fpmArgs[] = "$provide = $phpVersion-$iteration";
                }
            }
        }

        if (isset($config['replaces']) && is_array($config['replaces'])) {
            foreach ($config['replaces'] as $replace) {
                $fpmArgs[] = '--replaces';
                $fpmArgs[] = "$replace < {$phpVersion}-{$iteration}";
            }
        }

        if (isset($config['conflicts']) && is_array($config['conflicts'])) {
            foreach ($config['conflicts'] as $conflict) {
                $fpmArgs[] = '--conflicts';
                $fpmArgs[] = $conflict;
            }
        }

        foreach (self::$binaryDependencies as $lib => $version) {
            $fpmArgs[] = '--depends';
            $fpmArgs[] = "{$lib}({$version})(64bit)";
        }
        if (isset($config['depends']) && is_array($config['depends'])) {
            foreach ($config['depends'] as $depend) {
                $fpmArgs[] = '--depends';
                if (preg_match('/\.so(\.\d+)*$/', $depend)) {
                    $depend .= '()(64bit)';
                }
                $fpmArgs[] = $depend;
            }
        }

        if (isset($config['directories']) && is_array($config['directories'])) {
            foreach ($config['directories'] as $dir) {
                $fpmArgs[] = '--directories';
                $fpmArgs[] = $dir;
            }
        }

        if (isset($config['config-files']) && is_array($config['config-files'])) {
            foreach ($config['config-files'] as $configFile) {
                $fpmArgs[] = '--config-files';
                $fpmArgs[] = $configFile;
            }
        }

        if (isset($config['files']) && is_array($config['files'])) {
            foreach ($config['files'] as $source => $dest) {
                if (file_exists($source)) {
                    $fpmArgs[] = $source . '=' . $dest;
                }
                else {
                    echo "Warning: Source file not found: {$source}\n";
                }
            }
        }

        if (isset($config['empty_directories']) && is_array($config['empty_directories'])) {
            $emptyDir = TEMP_DIR . '/spp_empty';
            if (!file_exists($emptyDir) && !mkdir($emptyDir, 0755, true) && !is_dir($emptyDir)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $emptyDir));
            }
            if (is_dir($emptyDir)) {
                $files = array_diff(scandir($emptyDir), ['.', '..']);
                if (!empty($files)) {
                    exec('rm -rf ' . escapeshellarg($emptyDir . '/*'));
                }
            }
            foreach ($config['empty_directories'] as $dir) {
                $fpmArgs[] = $emptyDir . '=' . $dir;
            }
        }

        $rpmProcess = new Process($fpmArgs);
        $rpmProcess->setTimeout(null);
        $rpmProcess->run(function ($type, $buffer) {
            echo $buffer;
        });
        if (!$rpmProcess->isSuccessful()) {
            throw new \RuntimeException("RPM package creation failed: " . $rpmProcess->getErrorOutput());
        }
    }

    private static function createDebPackage(
        \staticphp\package $package,
        string $phpVersion,
        string $architecture,
        string $iteration,
        bool $isDebuginfo = false,
    ): void
    {
        self::$currentPackageType = 'deb';
        $name = $isDebuginfo ? $package->getName() . '-debuginfo' : $package->getName();
        $config = $isDebuginfo ? $package->getDebuginfoFpmConfig() : $package->getFpmConfig();
        $extraArgs = $isDebuginfo ? [] : $package->getFpmExtraArgs();

        echo "Creating DEB package for {$name}...\n";

        //$osRelease = parse_ini_file('/etc/os-release');
        //$distroCodename = $osRelease['VERSION_CODENAME'] ?? null;
        //$debIteration = $distroCodename !== '' ? "{$iteration}~{$distroCodename}" : $iteration;
        $debIteration = $iteration;
        $fullVersion = "{$phpVersion}-{$debIteration}";

        $fpmArgs = [...[
            'fpm',
            '-s', 'dir',
            '-t', 'deb',
            '--deb-compression', 'xz',
            '-p', DIST_DEB_PATH,
            '--name', $name,
            '--version', $phpVersion,
            '--architecture', $architecture,
            '--iteration', $debIteration,       // Debian revision (includes distro)
            '--description', "Static PHP Package for {$name}",
            '--license', $package->getLicense(),
            '--maintainer', 'Marc Henderkes <debs@henderkes.com>',
            '--vendor', 'Marc Henderkes <debs@henderkes.com>',
            '--url', 'debs.henderkes.com',
        ], ...$extraArgs];

        // Ensure non-CLI packages depend on the same PHP major.minor as php-zts-cli (ignore iteration/patch)
        // IMPORTANT: Use the actual PHP runtime version, not the package's own version (extensions have their own versioning)
        if ($name !== self::getPrefix() . '-cli') {
            [$fullPhpVersion] = self::getPhpVersionAndArchitecture();
            if (preg_match('/^(\d+)\.(\d+)/', $fullPhpVersion, $m)) {
                $maj = (int)$m[1];
                $min = (int)$m[2];
                $nextMin = $min + 1;
                $lowerBound = sprintf('%d.%d', $maj, $min);
                // For Debian, use an upper bound with tilde to exclude the next minor and its pre-releases
                $upperBound = sprintf('%d.%d~', $maj, $nextMin);
                $fpmArgs[] = '--depends';
                $fpmArgs[] = self::getPrefix() . "-cli (>= {$lowerBound})";
                $fpmArgs[] = '--depends';
                $fpmArgs[] = self::getPrefix() . "-cli (<< {$upperBound})";
            }
        }

        // If this is a debuginfo package, make it depend exactly on its base package version-iteration
        if (str_ends_with($name, '-debuginfo')) {
            $base = preg_replace('/-debuginfo$/', '', $name);
            $fpmArgs[] = '--depends';
            $fpmArgs[] = sprintf('%s (= %s)', $base, $fullVersion);
        }

        if (isset($config['provides']) && is_array($config['provides'])) {
            foreach ($config['provides'] as $provide) {
                $fpmArgs[] = '--provides';
                $fpmArgs[] = "{$provide} (= {$fullVersion})";
            }
        }

        if (isset($config['replaces']) && is_array($config['replaces'])) {
            foreach ($config['replaces'] as $replace) {
                $fpmArgs[] = '--replaces';
                $fpmArgs[] = "{$replace} (<= {$fullVersion})";
            }
        }

        if (isset($config['conflicts']) && is_array($config['conflicts'])) {
            foreach ($config['conflicts'] as $conflict) {
                $fpmArgs[] = '--conflicts';
                $fpmArgs[] = $conflict;
            }
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
        foreach (self::$binaryDependencies as $lib => $version) {
            if (isset($systemLibraryMap[$lib])) {
                // Use mapped name for system libraries
                $packageName = $systemLibraryMap[$lib];
            }
            else {
                // For other libraries, remove .so suffix
                $packageName = preg_replace('/\.so(\.\d+)?$/', '', $lib);
            }

            $numericVersion = preg_replace('/[^0-9.]/', '', $version);
            $fpmArgs[] = '--depends';
            $fpmArgs[] = "{$packageName} (>= {$numericVersion})";
        }
        if (isset($config['depends']) && is_array($config['depends'])) {
            foreach ($config['depends'] as $depend) {
                $fpmArgs[] = '--depends';
                $fpmArgs[] = $depend;
            }
        }

        if (isset($config['directories']) && is_array($config['directories'])) {
            foreach ($config['directories'] as $dir) {
                $fpmArgs[] = '--directories';
                $fpmArgs[] = $dir;
            }
        }

        if (isset($config['config-files']) && is_array($config['config-files'])) {
            foreach ($config['config-files'] as $configFile) {
                $fpmArgs[] = '--config-files';
                $fpmArgs[] = $configFile;
            }
        }
        $fpmArgs[] = '--deb-no-default-config-files';

        if (isset($config['files']) && is_array($config['files'])) {
            foreach ($config['files'] as $source => $dest) {
                if (file_exists($source)) {
                    $fpmArgs[] = $source . '=' . $dest;
                }
                else {
                    echo "Warning: Source file not found: {$source}\n";
                }
            }
        }

        if (isset($config['empty_directories']) && is_array($config['empty_directories'])) {
            $emptyDir = TEMP_DIR . '/spp_empty';
            if (!file_exists($emptyDir) && !mkdir($emptyDir, 0755, true) && !is_dir($emptyDir)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $emptyDir));
            }
            if (is_dir($emptyDir)) {
                $files = array_diff((array)scandir($emptyDir), ['.', '..']);
                if (!empty($files)) {
                    exec('rm -rf ' . escapeshellarg($emptyDir . '/*'));
                }
            }
            foreach ($config['empty_directories'] as $dir) {
                $fpmArgs[] = $emptyDir . '=' . $dir;
            }
        }

        $debProcess = new Process($fpmArgs);
        $debProcess->setTimeout(null);
        $debProcess->run(function ($type, $buffer) {
            echo $buffer;
        });

        echo "DEB package created: " . DIST_DEB_PATH . "/{$name}_{$phpVersion}-{$debIteration}_{$architecture}.deb\n";
    }

    private static function createApkPackage(\staticphp\package $package, string $phpVersion, string $architecture, string $iteration, bool $isDebuginfo = false): void
    {
        self::$currentPackageType = 'apk';
        $name = $isDebuginfo ? $package->getName() . '-debuginfo' : $package->getName();
        $config = $isDebuginfo ? $package->getDebuginfoFpmConfig() : $package->getFpmConfig();
        $extraArgs = $isDebuginfo ? [] : $package->getFpmExtraArgs();

        echo "Creating APK package for {$name}...\n";

        // APK uses r{iteration} format for revision number
        $apkIteration = $iteration;
        $fullVersion = "{$phpVersion}-r{$apkIteration}";

        $fpmArgs = [...[
            'fpm',
            '-s', 'dir',
            '-t', 'apk',
            '-p', DIST_APK_PATH,
            '--name', $name,
            '--version', $phpVersion,
            '--architecture', $architecture,
            '--iteration', $apkIteration,
            '--description', "Static PHP Package for {$name}",
            '--license', $package->getLicense(),
            '--maintainer', 'Marc Henderkes <apks@henderkes.com>',
            '--vendor', 'Marc Henderkes <apks@henderkes.com>',
            '--url', 'apks.henderkes.com',
        ], ...$extraArgs];

        // Ensure non-CLI packages depend on the same PHP major.minor as php-zts-cli (ignore iteration/patch)
        if ($name !== self::getPrefix() . '-cli') {
            [$fullPhpVersion] = self::getPhpVersionAndArchitecture();
            if (preg_match('/^(\d+)\.(\d+)/', $fullPhpVersion, $m)) {
                $maj = (int)$m[1];
                $min = (int)$m[2];
                $nextMin = $min + 1;
                $lowerBound = sprintf('%d.%d', $maj, $min);
                $upperBound = sprintf('%d.%d', $maj, $nextMin);
                // APK dependency format: package>=version and package<version
                $fpmArgs[] = '--depends';
                $fpmArgs[] = self::getPrefix() . "-cli>={$lowerBound}";
                $fpmArgs[] = '--depends';
                $fpmArgs[] = self::getPrefix() . "-cli<{$upperBound}";
            }
        }

        // If this is a debuginfo package, make it depend exactly on its base package version-iteration
        if (str_ends_with($name, '-debuginfo')) {
            $base = preg_replace('/-debuginfo$/', '', $name);
            $fpmArgs[] = '--depends';
            $fpmArgs[] = sprintf('%s=%s', $base, $fullVersion);
        }

        if (isset($config['provides']) && is_array($config['provides'])) {
            foreach ($config['provides'] as $provide) {
                $fpmArgs[] = '--provides';
                $fpmArgs[] = "{$provide}={$fullVersion}";
            }
        }

        if (isset($config['replaces']) && is_array($config['replaces'])) {
            foreach ($config['replaces'] as $replace) {
                $fpmArgs[] = '--replaces';
                $fpmArgs[] = $replace;
            }
        }

        if (isset($config['conflicts']) && is_array($config['conflicts'])) {
            foreach ($config['conflicts'] as $conflict) {
                $fpmArgs[] = '--conflicts';
                $fpmArgs[] = $conflict;
            }
        }

        // Alpine library dependencies - simpler naming than Debian
        foreach (self::$binaryDependencies as $lib => $version) {
            // For Alpine, we can use a simpler approach - most .so files map to package names
            // by removing the .so suffix and version numbers
            $packageName = preg_replace('/\.so(\.\d+)*$/', '', $lib);

            // Common Alpine package mappings
            $alpineLibMap = [
                'ld-linux-x86-64' => 'musl',
                'ld-linux-aarch64' => 'musl',
                'libc' => 'musl',
                'libm' => 'musl',
                'libpthread' => 'musl',
                'libutil' => 'musl',
                'libdl' => 'musl',
                'librt' => 'musl',
                'libresolv' => 'musl',
            ];

            if (isset($alpineLibMap[$packageName])) {
                $packageName = $alpineLibMap[$packageName];
            }

            $numericVersion = preg_replace('/[^0-9.]/', '', $version);
            $fpmArgs[] = '--depends';
            $fpmArgs[] = "{$packageName}>={$numericVersion}";
        }

        if (isset($config['depends']) && is_array($config['depends'])) {
            foreach ($config['depends'] as $depend) {
                $fpmArgs[] = '--depends';
                $fpmArgs[] = $depend;
            }
        }

        if (isset($config['directories']) && is_array($config['directories'])) {
            foreach ($config['directories'] as $dir) {
                $fpmArgs[] = '--directories';
                $fpmArgs[] = $dir;
            }
        }

        if (isset($config['config-files']) && is_array($config['config-files'])) {
            foreach ($config['config-files'] as $configFile) {
                $fpmArgs[] = '--config-files';
                $fpmArgs[] = $configFile;
            }
        }

        if (isset($config['files']) && is_array($config['files'])) {
            foreach ($config['files'] as $source => $dest) {
                if (file_exists($source)) {
                    $fpmArgs[] = $source . '=' . $dest;
                }
                else {
                    echo "Warning: Source file not found: {$source}\n";
                }
            }
        }

        if (isset($config['empty_directories']) && is_array($config['empty_directories'])) {
            $emptyDir = TEMP_DIR . '/spp_empty';
            if (!file_exists($emptyDir) && !mkdir($emptyDir, 0755, true) && !is_dir($emptyDir)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $emptyDir));
            }
            if (is_dir($emptyDir)) {
                $files = array_diff((array)scandir($emptyDir), ['.', '..']);
                if (!empty($files)) {
                    exec('rm -rf ' . escapeshellarg($emptyDir . '/*'));
                }
            }
            foreach ($config['empty_directories'] as $dir) {
                $fpmArgs[] = $emptyDir . '=' . $dir;
            }
        }

        $apkProcess = new Process($fpmArgs);
        $apkProcess->setTimeout(null);
        $apkProcess->run(function ($type, $buffer) {
            echo $buffer;
        });

        echo "APK package created: " . DIST_APK_PATH . "/{$name}-{$phpVersion}-r{$apkIteration}.{$architecture}.apk\n";
    }

    private static function getPhpVersionAndArchitecture(): array
    {
        if (!empty(self::$versionArch)) {
            return self::$versionArch;
        }
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

        self::$versionArch = [$fullPhpVersion, $architecture];
        return [$fullPhpVersion, $architecture];
    }

    private static function getBinaryDependencies(string $binaryPath): array
    {
        $process = new Process(['ldd', '-v', $binaryPath]);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException("ldd failed: " . $process->getErrorOutput());
        }

        $output = $process->getOutput();

        $output = preg_replace('/.*?' . preg_quote($binaryPath, '/') . ':\s*\n/s', '', $output, 1);

        $output = preg_replace('/\n\s*\/.*?:.*/s', '', $output, 1);

        $lines = explode("\n", $output);
        $dependencies = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            if (preg_match('#^([\w.\-+]+)\s+\(([^)]+)\)\s+=>\s+(/\S+)$#', $trimmed, $m)) {
                $lib = $m[1];
                $version = $m[2];

                if (!preg_match('/\d+(\.\d+)+/', $version)) {
                    continue;
                }

                if (!isset($dependencies[$lib]) || version_compare($version, $dependencies[$lib], '>')) {
                    $dependencies[$lib] = $version;
                }
            }
        }

        return $dependencies;
    }

    private static function getNextIteration(string $name, string $phpVersion, string $architecture): int
    {
        $maxIteration = 0;

        $rpmPattern = DIST_RPM_PATH . "/{$name}-{$phpVersion}-*.{$architecture}.rpm";
        $rpmFiles = glob($rpmPattern);

        foreach ($rpmFiles as $file) {
            if (preg_match("/{$name}-{$phpVersion}-(\d+)\.{$architecture}\.rpm$/", $file, $matches)) {
                $iteration = (int)$matches[1];
                $maxIteration = max($maxIteration, $iteration);
            }
        }

        $debPattern = DIST_DEB_PATH . "/{$name}_{$phpVersion}-*_{$architecture}.deb";
        $debFiles = glob($debPattern);

        foreach ($debFiles as $file) {
            if (preg_match("/{$name}_{$phpVersion}-(\d+)_{$architecture}\.deb$/", $file, $matches)) {
                $iteration = (int)$matches[1];
                $maxIteration = max($maxIteration, $iteration);
            }
        }

        $apkPattern = DIST_APK_PATH . "/{$name}-{$phpVersion}-r*.{$architecture}.apk";
        $apkFiles = glob($apkPattern);

        foreach ($apkFiles as $file) {
            if (preg_match("/{$name}-{$phpVersion}-r(\d+)\.{$architecture}\.apk$/", $file, $matches)) {
                $iteration = (int)$matches[1];
                $maxIteration = max($maxIteration, $iteration);
            }
        }

        return $maxIteration + 1;
    }

    public static function getPrefix(): string
    {
        $phpVersion = SPP_PHP_VERSION;

        // RPM packages always use php-zts (for module system)
        if (self::$currentPackageType === 'rpm') {
            return 'php-zts';
        }

        // APK packages use php-zts83 (no dot)
        if (self::$currentPackageType === 'apk') {
            if (preg_match('/^(\d+)\.(\d+)/', $phpVersion, $matches)) {
                return 'php-zts' . $matches[1] . $matches[2];
            }
            return 'php-zts';
        }

        // DEB packages use php-zts8.3 (with dot)
        if (preg_match('/^(\d+)\.(\d+)/', $phpVersion, $matches)) {
            return 'php-zts' . $matches[1] . '.' . $matches[2];
        }
        return 'php-zts';
    }

    /**
     * Get list of versioned package names to conflict/replace with
     * For example, for php-zts8.5-cli, returns [php-zts8.0-cli, php-zts8.1-cli, ..., php-zts8.9-cli] excluding 8.5
     * For RPM packages, returns empty array (RPM uses module system instead)
     */
    public static function getVersionedConflicts(string $suffix): array
    {
        // RPM packages use module system, no versioned conflicts needed
        if (self::$currentPackageType === 'rpm') {
            return [];
        }

        $conflicts = [];
        $phpVersion = SPP_PHP_VERSION;

        if (!preg_match('/^(\d+)\.(\d+)/', $phpVersion, $matches)) {
            return [];
        }

        $currentMajor = (int)$matches[1];
        $currentMinor = (int)$matches[2];

        // Generate conflicts for versions 8.0 through 8.9
        for ($minor = 0; $minor <= 9; $minor++) {
            // Skip the current version
            if ($currentMajor === 8 && $minor === $currentMinor) {
                continue;
            }

            // APK uses php-zts83 format (no dot), DEB uses php-zts8.3 (with dot)
            if (self::$currentPackageType === 'apk') {
                $conflicts[] = "php-zts{$currentMajor}{$minor}{$suffix}";
            } else {
                $conflicts[] = "php-zts{$currentMajor}.{$minor}{$suffix}";
            }
        }

        return $conflicts;
    }
}
