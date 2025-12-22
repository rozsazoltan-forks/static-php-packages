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
    private static string $packageType = 'rpm';
    private static ?string $iterationOverride = null;
    private static string $prefix = '-zts';
    private static bool $debuginfo = false;

    public static function run($packageNames = null, ?string $iteration = null, ?bool $debuginfo = null): true
    {
        self::loadConfig();

        define('DOWNLOAD_PATH', BUILD_ROOT_PATH . '/download');
        @mkdir(DOWNLOAD_PATH, 0755, true);

        $phpBinary = BUILD_BIN_PATH . '/php';
        self::$binaryDependencies = self::getBinaryDependencies($phpBinary);

        // Use values from constants set by BaseCommand
        self::$prefix = defined('SPP_PREFIX') ? SPP_PREFIX : '-zts';
        self::$packageType = defined('SPP_TYPE') ? SPP_TYPE : 'rpm';
        self::$iterationOverride = $iteration !== null && $iteration !== '' ? $iteration : null;

        // Set debuginfo flag from parameter
        if ($debuginfo !== null) {
            self::$debuginfo = $debuginfo;
        }

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
            // Create metapackage for APK to allow "apk add php-zts85"
            if (self::$packageType === 'apk') {
                self::createGenericPackage('meta');
            }
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

        self::createPackageWithFpm($package, $pkgVersion, $architecture);

        // Create debuginfo packages: always for RPM, only if --debuginfo flag set for others
        $dbgConfig = $package->getDebuginfoFpmConfig();
        if (is_array($dbgConfig) && !empty($dbgConfig['files'])) {
            if (self::$packageType === 'rpm' || self::$debuginfo) {
                self::createPackageWithFpm($package, $pkgVersion, $architecture, true);
            }
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
            $package->createPackages(self::$packageType, self::$binaryDependencies, self::$iterationOverride, self::$debuginfo);
            return;
        }

        [$phpVersion, $architecture] = self::getPhpVersionAndArchitecture();

        $package = new $packageClass();

        self::createPackageWithFpm($package, $phpVersion, $architecture);

        // Create debuginfo packages: always for RPM, only if --debuginfo flag set for others
        $dbgConfig = $package->getDebuginfoFpmConfig();
        if (is_array($dbgConfig) && !empty($dbgConfig['files'])) {
            if (self::$packageType === 'rpm' || self::$debuginfo) {
                self::createPackageWithFpm($package, $phpVersion, $architecture, true);
            }
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

        $package = new extension($extension);
        $packageClass = "\\staticphp\\package\\{$extension}";
        if (class_exists($packageClass)) {
            $package = new $packageClass($extension);
        }

        if (!file_exists(INI_PATH . '/extension/' . $extension . '.ini')) {
            echo "Warning: INI file for extension {$extension} not found, skipping package creation.\n";
            return;
        }

        self::createPackageWithFpm($package, $extensionVersion, $architecture);

        // Create debuginfo packages: always for RPM, only if --debuginfo flag set for others
        $dbgConfig = $package->getDebuginfoFpmConfig();
        if (is_array($dbgConfig) && !empty($dbgConfig['files'])) {
            if (self::$packageType === 'rpm' || self::$debuginfo) {
                self::createPackageWithFpm($package, $extensionVersion, $architecture, true);
            }
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

    private static function createPackageWithFpm(\staticphp\package $package, string $phpVersion, string $architecture, bool $isDebuginfo = false): void
    {
        if (self::$packageType === 'rpm') {
            self::createRpmPackage($package, $phpVersion, $architecture, $isDebuginfo);
        }

        if (self::$packageType === 'deb') {
            self::createDebPackage($package, $phpVersion, $architecture, $isDebuginfo);
        }

        if (self::$packageType === 'apk') {
            self::createApkPackage($package, $phpVersion, $architecture, $isDebuginfo);
        }
    }

    private static function createRpmPackage(\staticphp\package $package, string $phpVersion, string $architecture, bool $isDebuginfo = false): void
    {
        $name = $isDebuginfo ? $package->getName() . '-debuginfo' : $package->getName();
        $config = $isDebuginfo ? $package->getDebuginfoFpmConfig() : $package->getFpmConfig();
        $extraArgs = $isDebuginfo ? [] : $package->getFpmExtraArgs();

        echo "Creating RPM package for {$name}...\n";

        // Calculate iteration for RPM (with possible override)
        $computed = (string)self::getNextIteration($name, $phpVersion, $architecture, 'rpm');
        $iteration = self::$iterationOverride ?? $computed;

        // Generate full package filename with PHP version suffix and distribution version
        $phpSuffix = self::getPhpVersionSuffix();
        $distVersion = self::getDistVersion();
        $distSuffix = $distVersion !== '' ? ".{$distVersion}" : '';
        $packageFile = DIST_RPM_PATH . "/{$name}-{$phpVersion}-{$iteration}.{$phpSuffix}{$distSuffix}.{$architecture}.rpm";

        $fpmArgs = [...[
            'fpm',
            '-s', 'dir',
            '-t', 'rpm',
            '--rpm-compression', 'xz',
            '-p', $packageFile,  // Full path with phpSuffix and distVersion in filename
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

        echo "RPM package created: {$packageFile}\n";
    }

    private static function createDebPackage(
        \staticphp\package $package,
        string $phpVersion,
        string $architecture,
        bool $isDebuginfo = false,
    ): void
    {
        $name = $isDebuginfo ? $package->getName() . '-debuginfo' : $package->getName();
        $config = $isDebuginfo ? $package->getDebuginfoFpmConfig() : $package->getFpmConfig();
        $extraArgs = $isDebuginfo ? [] : $package->getFpmExtraArgs();

        echo "Creating DEB package for {$name}...\n";

        // Calculate iteration for DEB (with possible override)
        $computed = (string)self::getNextIteration($name, $phpVersion, $architecture, 'deb');
        $iteration = self::$iterationOverride ?? $computed;

        //$osRelease = parse_ini_file('/etc/os-release');
        //$distroCodename = $osRelease['VERSION_CODENAME'] ?? null;
        //$debIteration = $distroCodename !== '' ? "{$iteration}~{$distroCodename}" : $iteration;
        $debIteration = $iteration;
        $fullVersion = "{$phpVersion}-{$debIteration}";

        // Generate full package filename with PHP version suffix
        $phpSuffix = self::getPhpVersionSuffix();
        $packageFile = DIST_DEB_PATH . "/{$name}_{$phpVersion}-{$debIteration}.{$phpSuffix}_{$architecture}.deb";

        $fpmArgs = [...[
            'fpm',
            '-s', 'dir',
            '-t', 'deb',
            '--deb-compression', 'xz',
            '-p', $packageFile,  // Full path with phpSuffix in filename
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
                    // Check if this is a binary that needs its debug link fixed
                    // Only fix binaries in BUILD_BIN_PATH that are being renamed
                    if (str_starts_with($source, BUILD_BIN_PATH . '/') &&
                        is_executable($source) &&
                        basename($source) !== basename($dest)) {
                        // Fix the debug link and use the temporary binary instead
                        $source = self::fixBinaryDebugLink($source, $dest);
                    }
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
        if (!$debProcess->isSuccessful()) {
            throw new \RuntimeException("DEB package creation failed: " . $debProcess->getErrorOutput());
        }

        echo "DEB package created: {$packageFile}\n";
    }

    private static function createApkPackage(\staticphp\package $package, string $phpVersion, string $architecture, bool $isDebuginfo = false): void
    {
        $name = $isDebuginfo ? $package->getName() . '-debuginfo' : $package->getName();
        $config = $isDebuginfo ? $package->getDebuginfoFpmConfig() : $package->getFpmConfig();
        $extraArgs = $isDebuginfo ? [] : $package->getFpmExtraArgs();

        echo "Creating APK package for {$name} using nfpm...\n";

        // Calculate iteration for APK (with possible override)
        $computed = (string)self::getNextIteration($name, $phpVersion, $architecture, 'apk');
        $iteration = self::$iterationOverride ?? $computed;

        // APK uses r{iteration} format for revision number
        $apkIteration = $iteration;
        $fullVersion = "{$phpVersion}-r{$apkIteration}";

        // Use nfpm instead of fpm for APK packages
        self::createApkWithNfpm($package, $name, $phpVersion, $architecture, $apkIteration, $config, $isDebuginfo);
    }
    private static function createApkWithNfpm(\staticphp\package $package, string $name, string $phpVersion, string $architecture, string $iteration, array $config, bool $isDebuginfo): void
    {
        $fullVersion = "{$phpVersion}-r{$iteration}";

        // Create nfpm YAML config
        $nfpmConfig = [
            'name' => $name,
            'arch' => $architecture,
            'platform' => 'linux',
            'version' => $phpVersion,
            'release' => $iteration,
            'section' => 'default',
            'priority' => 'optional',
            'maintainer' => 'Marc Henderkes <apks@henderkes.com>',
            'description' => "Static PHP Package for {$name}",
            'vendor' => 'Marc Henderkes',
            'homepage' => 'https://apks.henderkes.com',
            'license' => $package->getLicense(),
            'apk' => [
                'signature' => [
                    'key_name' => self::getPrefix(),
                ],
            ],
        ];

        // Build dependencies
        $depends = [];

        // Ensure non-CLI packages depend on the same PHP major.minor
        if ($name !== self::getPrefix() . '-cli') {
            [$fullPhpVersion] = self::getPhpVersionAndArchitecture();
            if (preg_match('/^(\d+)\.(\d+)/', $fullPhpVersion, $m)) {
                $maj = (int)$m[1];
                $min = (int)$m[2];
                $nextMin = $min + 1;
                $lowerBound = sprintf('%d.%d', $maj, $min);
                $upperBound = sprintf('%d.%d', $maj, $nextMin);
                $depends[] = self::getPrefix() . "-cli>={$lowerBound}";
                $depends[] = self::getPrefix() . "-cli<{$upperBound}";
            }
        }

        // Debuginfo packages depend on their base package
        if (str_ends_with($name, '-debuginfo')) {
            $base = preg_replace('/-debuginfo$/', '', $name);
            $depends[] = sprintf('%s=%s', $base, $fullVersion);
        }

        // Alpine library dependencies
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
            'libgcc_s' => 'libgcc',
        ];

        foreach (self::$binaryDependencies as $lib => $version) {
            $packageName = preg_replace('/\.so(\.\d+)*$/', '', $lib);
            if (isset($alpineLibMap[$packageName])) {
                $packageName = $alpineLibMap[$packageName];
            }
            $numericVersion = preg_replace('/[^0-9.]/', '', $version);
            $depends[] = "{$packageName}>={$numericVersion}";
        }

        if (isset($config['depends']) && is_array($config['depends'])) {
            $depends = array_merge($depends, $config['depends']);
        }

        if (!empty($depends)) {
            $nfpmConfig['depends'] = $depends;
        }

        // Add provides, replaces, conflicts
        if (isset($config['provides']) && is_array($config['provides'])) {
            // For APK cli packages: filter out the base prefix from provides since we have a separate meta package
            // This prevents conflicts between php-zts-cli (which provides php-zts) and the php-zts meta package
            $provides = $config['provides'];
            if ($name === self::getPrefix() . '-cli') {
                $provides = array_values(array_filter($provides, fn($p) => $p !== self::getPrefix()));
                $provides = array_values($provides);
            }
            $nfpmConfig['provides'] = $provides;
        }
        if (isset($config['replaces']) && is_array($config['replaces'])) {
            $nfpmConfig['replaces'] = $config['replaces'];
        }
        if (isset($config['conflicts']) && is_array($config['conflicts'])) {
            $nfpmConfig['conflicts'] = $config['conflicts'];
        }

        // Build contents (files)
        $contents = [];
        if (isset($config['files']) && is_array($config['files'])) {
            foreach ($config['files'] as $source => $dest) {
                if (file_exists($source)) {
                    // Fix debug link for renamed binaries
                    if (str_starts_with($source, BUILD_BIN_PATH . '/') &&
                        is_executable($source) &&
                        basename($source) !== basename($dest)) {
                        $source = self::fixBinaryDebugLink($source, $dest);
                    }
                    $contentItem = ['src' => $source, 'dst' => $dest];
                    // Mark config files
                    if (isset($config['config-files']) && in_array($dest, $config['config-files'])) {
                        $contentItem['type'] = 'config';
                    }
                    $contents[] = $contentItem;
                } else {
                    echo "Warning: Source file not found: {$source}\n";
                }
            }
        }

        // Handle empty directories
        if (isset($config['empty_directories']) && is_array($config['empty_directories'])) {
            foreach ($config['empty_directories'] as $dir) {
                $contents[] = ['dst' => $dir, 'type' => 'dir'];
            }
        }

        if (!empty($contents)) {
            $nfpmConfig['contents'] = $contents;
        }

        // Write nfpm config to YAML file
        $nfpmConfigFile = TEMP_DIR . "/nfpm-{$name}.yaml";
        if (!yaml_emit_file($nfpmConfigFile, $nfpmConfig, YAML_UTF8_ENCODING)) {
            throw new \RuntimeException("Failed to write YAML file: {$nfpmConfigFile}");
        }

        echo "nfpm config written to: {$nfpmConfigFile}\n";

        // Run nfpm to create the package with full filename including PHP version suffix
        $phpSuffix = self::getPhpVersionSuffix();
        $outputFile = DIST_APK_PATH . "/{$name}-{$phpVersion}-r{$iteration}.{$phpSuffix}.{$architecture}.apk";
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

        // Clean up config file
        @unlink($nfpmConfigFile);

        echo "APK package created: {$outputFile}\n";
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
        // Detect if this is a musl binary
        $fileProcess = new Process(['file', $binaryPath]);
        $fileProcess->run();
        $fileOutput = $fileProcess->getOutput();
        $isMusl = str_contains($fileOutput, 'musl') || str_contains($fileOutput, 'statically linked');

        // For musl binaries, we need to use the musl dynamic linker instead of ldd
        if ($isMusl) {
            $output = self::getMuslBinaryDependencies($binaryPath);
        } else {
            $process = new Process(['ldd', '-v', $binaryPath]);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \RuntimeException("ldd failed: " . $process->getErrorOutput());
            }

            $output = $process->getOutput();
        }

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

    /**
     * Get dependencies for musl-linked binaries using the musl dynamic linker
     */
    private static function getMuslBinaryDependencies(string $binaryPath): string
    {
        // Detect architecture from the binary
        $archProcess = new Process(['uname', '-m']);
        $archProcess->run();
        $arch = trim($archProcess->getOutput());

        // Map architecture to musl loader name
        $archMap = [
            'x86_64' => 'x86_64',
            'aarch64' => 'aarch64',
            'arm64' => 'aarch64',
            'armv7l' => 'armv7',
            'armhf' => 'armhf',
        ];

        $muslArch = $archMap[$arch] ?? 'x86_64';

        // Try to find the musl dynamic linker in common locations
        $basePaths = ['/lib', '/usr/lib', '/usr/lib64'];
        $muslLoaders = [];

        foreach ($basePaths as $basePath) {
            $muslLoaders[] = "{$basePath}/ld-musl-{$muslArch}.so.1";
            // Also try without .1 suffix (some systems)
            $muslLoaders[] = "{$basePath}/ld-musl-{$muslArch}.so";
        }

        $muslLoader = null;
        foreach ($muslLoaders as $loader) {
            if (file_exists($loader)) {
                $muslLoader = $loader;
                break;
            }
        }

        if ($muslLoader === null) {
            throw new \RuntimeException("Could not find musl dynamic linker for architecture {$arch} (tried: " . implode(', ', $muslLoaders) . ")");
        }

        echo "Using musl dynamic linker: {$muslLoader}\n";

        // Use the musl loader to list dependencies
        $process = new Process([$muslLoader, '--list', $binaryPath]);
        $process->run();

        if (!$process->isSuccessful()) {
            // If the binary is statically linked, --list might fail
            // Check if it's actually static
            $readelfProcess = new Process(['readelf', '-d', $binaryPath]);
            $readelfProcess->run();
            if (!str_contains($readelfProcess->getOutput(), 'NEEDED')) {
                echo "Binary {$binaryPath} appears to be statically linked (no dynamic dependencies)\n";
                return '';
            }
            throw new \RuntimeException("Musl ldd failed: " . $process->getErrorOutput());
        }

        return $process->getOutput();
    }

    /**
     * Fix GNU debuglink in a binary to match its new filename
     * This is needed when binaries are renamed during packaging (e.g., php -> php-zts8.3)
     */
    private static function fixBinaryDebugLink(string $sourceBinary, string $targetBinaryName): string
    {
        // Extract just the filename from the target path
        $targetFilename = basename($targetBinaryName);
        $newDebugFileName = $targetFilename . '.debug';

        // Create a temporary copy of the binary to modify
        $tempBinary = TEMP_DIR . '/' . $targetFilename;

        // Copy the source binary to temp location
        if (!copy($sourceBinary, $tempBinary)) {
            echo "Warning: Failed to copy {$sourceBinary} to {$tempBinary}, debug link won't be fixed\n";
            return $sourceBinary;
        }

        // Ensure the temporary binary is executable
        chmod($tempBinary, 0755);

        // Find the original debug file
        // Map binary names to their debug files using the prefix
        $binaryName = basename($sourceBinary);
        $binarySuffix = getBinarySuffix();
        $debugMap = [
            'php' => BUILD_ROOT_PATH . '/debug/php.debug',
            'php-cgi' => BUILD_ROOT_PATH . '/debug/php-cgi.debug',
            'php-fpm' => BUILD_ROOT_PATH . '/debug/php-fpm.debug',
            'frankenphp' => BUILD_ROOT_PATH . '/debug/frankenphp.debug',
        ];

        $originalDebugFile = $debugMap[$binaryName] ?? null;

        // If no debug file exists, we can't fix the debug link
        if ($originalDebugFile === null || !file_exists($originalDebugFile)) {
            echo "No debug file found for {$binaryName}, skipping debug link fix\n";
            return $tempBinary;
        }

        // Create a temporary copy of the debug file with the new name
        // objcopy needs the actual file to exist to compute the checksum
        $tempDebugFile = TEMP_DIR . '/' . $newDebugFileName;
        if (!copy($originalDebugFile, $tempDebugFile)) {
            echo "Warning: Failed to copy debug file, debug link won't be fixed\n";
            return $tempBinary;
        }

        // Remove existing debug link
        $removeProcess = new Process(['objcopy', '--remove-section=.gnu_debuglink', $tempBinary]);
        $removeProcess->run();
        if (!$removeProcess->isSuccessful()) {
            echo "Warning: Failed to remove debug link from {$tempBinary}: " . $removeProcess->getErrorOutput() . "\n";
            @unlink($tempDebugFile);
            return $sourceBinary;
        }

        // Add new debug link pointing to the renamed debug file
        $addProcess = new Process(['objcopy', '--add-gnu-debuglink=' . $tempDebugFile, $tempBinary]);
        $addProcess->run();
        if (!$addProcess->isSuccessful()) {
            echo "Warning: Failed to add debug link to {$tempBinary}: " . $addProcess->getErrorOutput() . "\n";
            @unlink($tempDebugFile);
            return $sourceBinary;
        }

        echo "Fixed debug link in {$targetFilename}: {$newDebugFileName}\n";

        // Clean up the temporary debug file (we don't need it anymore, just needed it for objcopy)
        @unlink($tempDebugFile);

        return $tempBinary;
    }

    private static function getNextIteration(string $name, string $phpVersion, string $architecture, string $packageType): int
    {
        $maxIteration = 0;

        if ($packageType === 'rpm') {
            // RPM: {name}-{version}-{iteration}.{phpSuffix}.{distVersion}.{arch}.rpm
            // Also match old formats:
            // - {name}-{version}-{iteration}.{arch}.rpm (no suffix)
            // - {name}-{version}-{iteration}.{phpSuffix}.{arch}.rpm (no distVersion)
            $rpmPattern = DIST_RPM_PATH . "/{$name}-{$phpVersion}-*.rpm";
            $rpmFiles = glob($rpmPattern);

            foreach ($rpmFiles as $file) {
                // Match all formats: iteration followed by 0-2 parts, then arch.rpm
                if (preg_match("/{$name}-" . preg_quote($phpVersion, '/') . "-(\d+)(?:\.[^.]+){0,2}\.{$architecture}\.rpm$/", $file, $matches)) {
                    $iteration = (int)$matches[1];
                    $maxIteration = max($maxIteration, $iteration);
                }
            }
        }

        if ($packageType === 'deb') {
            // DEB: {name}_{version}-{iteration}.{phpSuffix}_{arch}.deb
            // Also match old format without phpSuffix: {name}_{version}-{iteration}_{arch}.deb
            $debPattern = DIST_DEB_PATH . "/{$name}_{$phpVersion}-*.deb";
            $debFiles = glob($debPattern);

            foreach ($debFiles as $file) {
                // Match both formats:
                // - New: {name}_{version}-{iteration}.{phpSuffix}_{arch}.deb
                // - Old: {name}_{version}-{iteration}_{arch}.deb (without phpSuffix)
                if (preg_match("/{$name}_" . preg_quote($phpVersion, '/') . "-(\d+)(?:\.[^_]+)?_{$architecture}\.deb$/", $file, $matches)) {
                    $iteration = (int)$matches[1];
                    $maxIteration = max($maxIteration, $iteration);
                }
            }
        }

        if ($packageType === 'apk') {
            // APK: {name}-{version}-r{iteration}.{phpSuffix}.{arch}.apk
            // Also match old format: {name}-{version}-r{iteration}.{arch}.apk (no phpSuffix)
            $apkPattern = DIST_APK_PATH . "/{$name}-{$phpVersion}-r*.apk";
            $apkFiles = glob($apkPattern);

            foreach ($apkFiles as $file) {
                // Match both formats: r{iteration} followed by 0-1 parts, then arch.apk
                if (preg_match("/{$name}-" . preg_quote($phpVersion, '/') . "-r(\d+)(?:\.[^.]+)?\.{$architecture}\.apk$/", $file, $matches)) {
                    $iteration = (int)$matches[1];
                    $maxIteration = max($maxIteration, $iteration);
                }
            }
        }

        return $maxIteration + 1;
    }

    public static function getPrefix(): string
    {
        // Return the prefix set by the user, prepended with "php"
        // For example: "-zts" becomes "php-zts", "-zts8.5" becomes "php-zts8.5"
        return 'php' . self::$prefix;
    }

    /**
     * Get PHP version suffix for package filenames (e.g., "static-83" for PHP 8.3)
     */
    private static function getPhpVersionSuffix(): string
    {
        [$phpVersion,] = self::getPhpVersionAndArchitecture();

        // Extract major.minor version (e.g., "8.3.29" -> "8.3")
        if (preg_match('/^(\d+)\.(\d+)/', $phpVersion, $matches)) {
            $majorMinorNoDot = $matches[1] . $matches[2]; // e.g., "83"
        } else {
            $majorMinorNoDot = str_replace('.', '', $phpVersion);
        }

        // Construct suffix: static-{version} (e.g., "static-83")
        return 'static-' . $majorMinorNoDot;
    }

    /**
     * Get distribution version for RPM filenames (e.g., "el9", "el8", "fc39")
     */
    private static function getDistVersion(): string
    {
        if (!file_exists('/etc/os-release')) {
            return '';
        }

        $osRelease = parse_ini_file('/etc/os-release');
        if (!$osRelease || !isset($osRelease['ID'], $osRelease['VERSION_ID'])) {
            return '';
        }

        $id = $osRelease['ID'];
        $versionId = $osRelease['VERSION_ID'];

        // Extract major version number
        if (preg_match('/^(\d+)/', $versionId, $matches)) {
            $majorVersion = $matches[1];
        } else {
            return '';
        }

        // Map distribution ID to prefix
        $distMap = [
            'rhel' => 'el',
            'centos' => 'el',
            'rocky' => 'el',
            'almalinux' => 'el',
            'fedora' => 'fc',
        ];

        $prefix = $distMap[$id] ?? '';
        return $prefix !== '' ? $prefix . $majorVersion : '';
    }

    /**
     * Get list of versioned package names to conflict/replace with
     * For example, for php-zts8.5-cli, returns [php-zts8.0-cli, php-zts8.1-cli, ..., php-zts8.9-cli] excluding 8.5
     * For RPM packages (using unversioned prefix like -zts), returns empty array (RPM uses module system instead)
     */
    public static function getVersionedConflicts(string $suffix): array
    {
        // Versioned packages can coexist - no conflicts
        return [];
    }
}
