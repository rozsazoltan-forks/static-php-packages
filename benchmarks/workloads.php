<?php
// In-process workloads: setup/autoloading and four warmups are outside the timer.
// Usage: php -d opcache.enable_cli=1 workloads.php WORKLOAD ITERATIONS UPSTREAM_FUNCTIONS AUTOLOAD
declare(strict_types=1);

if (!function_exists('opcache_get_status') || !(opcache_get_status(false)['opcache_enabled'] ?? false)) {
    throw new RuntimeException('Benchmarks require OPcache to be enabled.');
}

[$script, $name, $iterations, $functions, $autoload] = $argv;
$iterations = (int) $iterations;
if ($iterations < 1) {
    throw new InvalidArgumentException('iterations must be positive');
}

final class VmBenchValue
{
    public function __construct(public int $value) {}
    public function add(int $value): int { return $this->value += $value; }
}

function vmBenchGenerator(int $n): Generator
{
    for ($i = 0; $i < $n; $i++) {
        yield $i => ($i * 17) & 1023;
    }
}

$upstream = ['mandel' => [], 'mandel2' => [], 'ary3' => [2000],
             'nestedloop' => [12], 'fibo' => [30], 'hash2' => [500]];
if (isset($upstream[$name])) {
    require $functions;
    $work = static function () use ($name, $upstream): string {
        ob_start();
        $value = $name(...$upstream[$name]);
        return ob_get_clean() . serialize($value);
    };
} elseif ($name === 'objects') {
    $work = static function (): int {
        $sum = 0;
        for ($i = 0; $i < 10000; $i++) {
            $value = new VmBenchValue($i);
            for ($j = 0; $j < 20; $j++) {
                $sum += $value->add($j);
            }
        }
        return $sum;
    };
} elseif ($name === 'arrays') {
    $work = static function (): int {
        $values = [];
        for ($i = 0; $i < 10000; $i++) {
            $values['key-' . $i] = ['id' => $i, 'score' => ($i * 17) % 1000];
        }
        $copy = $values;
        $sum = 0;
        foreach ($copy as $key => $row) {
            $copy[$key]['score'] += $row['id'];
            $sum += $copy[$key]['score'];
        }
        return $sum + count($values);
    };
} elseif ($name === 'strings-json') {
    $rows = [];
    for ($i = 0; $i < 1000; $i++) {
        $rows[] = ['id' => $i, 'name' => "Product <$i>", 'price' => $i * 1.25,
                   'tags' => ['php', 'vm', 'benchmark']];
    }
    $work = static function () use ($rows): string {
        $encoded = json_encode($rows, JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        $output = '';
        foreach ($decoded as $row) {
            $output .= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') . ':' .
                implode(',', $row['tags']) . ':' . number_format($row['price'], 2) . "\n";
        }
        return hash('sha256', $output);
    };
} elseif ($name === 'exceptions') {
    $work = static function (): int {
        $sum = 0;
        for ($i = 0; $i < 10000; $i++) {
            try {
                throw new RuntimeException('expected', $i);
            } catch (RuntimeException $e) {
                $sum += $e->getCode();
            }
        }
        return $sum;
    };
} elseif ($name === 'generators') {
    $work = static function (): int {
        $sum = 0;
        foreach (vmBenchGenerator(100000) as $key => $value) {
            $sum += $key + $value;
        }
        return $sum;
    };
} elseif ($name === 'fibers') {
    $work = static function (): int {
        $fiber = new Fiber(static function (): int {
            $sum = 0;
            for ($i = 0; $i < 10000; $i++) {
                $sum += Fiber::suspend($i);
            }
            return $sum;
        });
        $value = $fiber->start();
        while (!$fiber->isTerminated()) {
            $value = $fiber->resume($value + 1);
        }
        return $fiber->getReturn();
    };
} elseif ($name === 'twig') {
    require $autoload;
    $twig = new Twig\Environment(new Twig\Loader\ArrayLoader([
        'page' => '<h1>{{ title }}</h1>{% for row in rows %}<article data-id="{{ row.id }}">' .
            '{{ row.name|upper }} {{ row.price|number_format(2) }} ' .
            '{% for tag in row.tags %}<b>{{ tag }}</b>{% endfor %}</article>{% endfor %}',
    ]), ['cache' => getenv('VM_BENCH_CACHE') ?: false, 'strict_variables' => true]);
    $rows = [];
    for ($i = 0; $i < 200; $i++) {
        $rows[] = ['id' => $i, 'name' => "Product <$i>", 'price' => $i * 1.25,
                   'tags' => ['php', 'vm', 'benchmark']];
    }
    $template = $twig->load('page');
    $work = static fn (): string => $template->render(['title' => 'Benchmark', 'rows' => $rows]);
} elseif ($name === 'symfony-yaml') {
    require $autoload;
    $yaml = '';
    for ($i = 0; $i < 100; $i++) {
        $yaml .= "service_$i:\n  class: App\\Service$i\n  public: false\n  tags: [worker, app]\n  calls:\n    - [setValue, [$i]]\n";
    }
    $work = static fn (): string => Symfony\Component\Yaml\Yaml::dump(
        Symfony\Component\Yaml\Yaml::parse($yaml), 6);
} else {
    throw new InvalidArgumentException("Unknown workload: $name");
}

$expected = (string) $work();
for ($i = 0; $i < 3; $i++) {
    if ((string) $work() !== $expected) {
        throw new RuntimeException('unstable warmup output');
    }
}
$cpu = static fn (array $r): float => $r['ru_utime.tv_sec'] + $r['ru_utime.tv_usec'] / 1e6 +
    $r['ru_stime.tv_sec'] + $r['ru_stime.tv_usec'] / 1e6;
$usage = getrusage();
$start = hrtime(true);
for ($i = 0; $i < $iterations; $i++) {
    if ((string) $work() !== $expected) {
        throw new RuntimeException('incorrect timed output');
    }
}
$seconds = (hrtime(true) - $start) / 1e9;
$endUsage = getrusage();
echo json_encode([
    'workload' => $name, 'vm' => ZEND_VM_KIND, 'iterations' => $iterations,
    'seconds' => $seconds, 'cpu_seconds' => $cpu($endUsage) - $cpu($usage),
    'checksum' => hash('sha256', $expected), 'peak_bytes' => memory_get_peak_usage(true),
    'maxrss_kib' => $endUsage['ru_maxrss'],
    'cached' => function_exists('opcache_is_script_cached') && opcache_is_script_cached(__FILE__),
], JSON_THROW_ON_ERROR), "\n";
