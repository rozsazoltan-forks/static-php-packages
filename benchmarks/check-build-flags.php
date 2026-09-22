<?php
// Render the supported build matrix, including prereleases and future versions.
require dirname(__DIR__) . '/vendor/autoload.php';
define('BASE_PATH', dirname(__DIR__));
define('SPP_TARGET', null);
function getSharedLibrarySuffix(): string { return '-zts-86'; }
function str_replace_first(string $search, string $replace, string $value): string
{
    return preg_replace('/' . preg_quote($search, '/') . '/', $replace, $value, 1);
}
function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$twig = new Twig\Environment(new Twig\Loader\FilesystemLoader(BASE_PATH . '/config/templates'));
check(function_exists('opcache_get_status') && (opcache_get_status(false)['opcache_enabled'] ?? false),
    'Validation requires OPcache to be enabled');
$parameters = ['inline-unit-growth=100', 'ipa-cp-unit-growth=100', 'large-function-growth=500',
               'max-inline-insns-auto=250', 'max-inline-insns-single=500'];
$count = 0;
foreach (['8.2' => false, '8.3' => false, '8.4' => false, '8.5' => false,
          '8.6' => true, '8.6.0RC2' => true, '8.10' => true, '9.0' => true] as $version => $lto) {
    foreach (['x86_64', 'aarch64'] as $arch) {
        $actual = yaml_parse(staticphp\util\TwigRenderer::renderCraftTemplate($version, $arch));
        check(str_contains($actual['extra-env']['SPC_CMD_VAR_PHP_MAKE_EXTRA_CFLAGS'], ' -flto') === $lto,
            'Renderer did not set version-dependent PHP LTO');
        foreach (['rpm' => ['7', '8', '9', '10'], 'deb' => ['13'], 'apk' => ['3.21']] as $type => $oses) {
            foreach ($oses as $os) {
                foreach ([null, 'native-native-gnu'] as $target) {
                    $env = yaml_parse(ltrim($twig->render('craft.yml.twig', [
                        'php_version' => $version, 'allow_shared_ext_failure' => $lto,
                        'target' => $target, 'arch' => $arch, 'os' => $os, 'type' => $type,
                        'prefix' => 'php-zts', 'release_suffix' => 'zts-86',
                        'moduledir' => '/usr/lib/php-zts/modules', 'ci' => false,
                    ])))['extra-env'];
                    $gcc = $target === null;
                    $cflags = $env['SPC_CMD_VAR_PHP_MAKE_EXTRA_CFLAGS'];
                    $ldflags = $env['SPC_CMD_VAR_PHP_MAKE_EXTRA_LDFLAGS'];
                    $compile = preg_split('/\s+/', trim($cflags));
                    $link = preg_split('/\s+/', trim($ldflags));
                    foreach (['CFLAGS', 'CXXFLAGS', 'LDFLAGS'] as $kind) {
                        $flags = $env['SPC_DEFAULT_' . $kind];
                        check(!str_contains($flags, '--param=') && !str_contains($flags, '-falign-functions')
                            && !str_contains($flags, '-fomit-frame-pointer'), 'PHP tuning leaked into dependencies');
                        check(str_contains($flags, '-flto') === !$gcc, 'Dependency LTO changed');
                    }
                    check(in_array('-flto', $compile, true) === (!$gcc || $lto), 'Incorrect compile LTO');
                    check(in_array('-flto', $link, true) === (!$gcc || $lto), 'Incorrect link LTO');
                    foreach ($parameters as $parameter) {
                        check(in_array('--param=' . $parameter, $compile, true) === ($gcc && $lto), 'Incorrect compile inlining');
                        check(in_array('-Wc,--param=' . $parameter, $link, true) === ($gcc && $lto), 'Inlining not forwarded through libtool');
                    }
                    check(in_array('-fomit-frame-pointer', $compile, true) === ($gcc && $lto), 'Incorrect PHP frame pointers');
                    check(in_array('-Wc,-fomit-frame-pointer', $link, true) === ($gcc && $lto), 'Frame-pointer option not forwarded through libtool');
                    if ($gcc && $lto) {
                        check(strrpos($cflags, ' -fomit-frame-pointer') > strrpos($cflags, ' -fno-omit-frame-pointer'),
                            'PHP frame-pointer override has the wrong order');
                    }
                    check(!str_contains($cflags, '-Wc,'), 'libtool link flag leaked into configure CFLAGS');
                    check(in_array('-falign-functions=64', $compile, true) === ($gcc && $lto && $arch === 'x86_64'),
                        'Incorrect compile alignment');
                    check(in_array('-Wc,-falign-functions=64', $link, true) === ($gcc && $lto && $arch === 'x86_64'),
                        'Incorrect shared-library alignment');
                    check(str_contains($env['SPC_CMD_PREFIX_PHP_CONFIGURE'], '--disable-gcc-global-regs') === $lto,
                        'Incorrect VM selection');
                    check($env['SPC_CMD_VAR_PHP_MAKE_EXTRA_LDFLAGS_LIBPHP'] === '-Wl,-Bsymbolic-functions',
                        'Redundant or missing libphp flags');
                    $count++;
                }
            }
        }
    }
}
echo "$count build configurations passed (medium inlining, frame pointers, LTO and VM selection).\n";
