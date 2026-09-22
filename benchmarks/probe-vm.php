<?php

if (!function_exists('opcache_get_status') || !(opcache_get_status(false)['opcache_enabled'] ?? false)) {
    throw new RuntimeException('VM benchmarks require OPcache to be enabled.');
}

if ($argc < 3 || (int) ($argv[3] ?? 30) < 1) {
    fwrite(STDERR, "Usage: php probe-vm.php functions.php mandel|mandel2|ary3|nestedloop|fibo [iterations>0]\n");
    exit(2);
}

// Pass a definitions-only copy of Zend/bench.php so OPcache can optimize the functions.
require $argv[1];
$name = $argv[2];
$iterations = (int) ($argv[3] ?? 30);
$args = match ($name) {
    'mandel', 'mandel2' => [],
    'ary3' => [2000],
    'nestedloop' => [12],
    'fibo' => [30],
};
for ($i = 0; $i < 3; $i++) {
    ob_start();
    $name(...$args);
    ob_end_clean();
}
$cpu0 = getrusage();
$start = hrtime(true);
for ($i = 0; $i < $iterations; $i++) {
    ob_start();
    $name(...$args);
    $output = ob_get_clean();
}
$elapsed = (hrtime(true) - $start) / 1e9;
$cpu1 = getrusage();
$cpu = static fn ($r) => $r['ru_utime.tv_sec'] + $r['ru_utime.tv_usec'] / 1e6 + $r['ru_stime.tv_sec'] + $r['ru_stime.tv_usec'] / 1e6;
echo json_encode(['vm' => ZEND_VM_KIND, 'workload' => $name, 'iterations' => $iterations, 'seconds' => $elapsed, 'cpu_seconds' => $cpu($cpu1) - $cpu($cpu0), 'checksum' => md5($output)]), "\n";
