# PHP 8.6 GCC: PHPStan on Symfony Demo and Phoronix PHPBench

The newer [FrankenPHP and compiler-flag study](php86-frankenphp-flags.md) adds
classic/worker HTTP measurements and independently repeats these application
benchmarks with additional tailcall compiler profiles, always with OPcache.

With **OPcache enabled in every reported run**, tuned GCC tailcall reduces
PHPStan's median command time by **1.9%** and increases the official PHPBench
score by **8.6%** relative to tuned GCC hybrid. Both use LTO and raised inlining
limits. Results and raw data are local; nothing was uploaded to OpenBenchmarking.

## PHPStan results

Tailcall is modestly faster on this application's full static analysis with
OPcache enabled. Negative change means less elapsed time:

| CLI OPcache | Hybrid median seconds | Tailcall median seconds | Tailcall change [95% CI] |
| --- | ---: | ---: | ---: |
| On | 6.777 | 6.646 | -1.9% [-5.6, -1.5] |

All **24 measured analyses** complete successfully with identical zero-error
JSON diagnostics and empty stderr. CPU-time medians also favor tailcall: 6.745
versus 6.561 seconds. Median absolute deviations are 1.1–1.5%. The slowest hybrid sample is 10.238
seconds; it remains in the raw data and all statistics, as does every other sample.

These timings include initializing and optimizing code in fresh CLI processes.
This result supports a modest application-level tailcall gain,
despite the recursive Fibonacci regression in the earlier microbenchmarks.

## Phoronix PHPBench results

Eight measured official-profile runs per VM after one warmup per VM:

| Metric | Hybrid median | Tailcall median | Tailcall change [95% CI] |
| --- | ---: | ---: | ---: |
| PHPBench score (higher is better) | 1,130,582 | 1,227,695 | +8.6% [+7.3, +12.5] |
| Implied timed duration, seconds | 17.690 | 16.291 | -7.9% [-11.1, -6.8] |

All **16 measured runs** complete all 56 subtests without a regression assertion
or fatal error. Median absolute deviations of timed duration are 0.9% for hybrid
and 0.5% for tailcall. The controller was resumed after the OPcache-only constraint
was set; completed enabled-mode samples were retained, and remaining balanced
blocks continued with the same binaries, flags and seed.

The largest absolute improvements are loops, `switch`, and ordinary function
calls. Representative gains and regressions are below; the raw analysis covers
all 56 subtests. These are seconds per complete subtest, with lower being better:

| Subtest | Hybrid | Tailcall | Time change [95% CI] |
| --- | ---: | ---: | ---: |
| `test_do_while` | 0.9464 | 0.6334 | -33.1% [-33.9, -30.4] |
| `test_while` | 0.9178 | 0.6327 | -31.1% [-33.1, -30.1] |
| `test_switch` | 0.8110 | 0.5997 | -26.1% [-27.1, -25.3] |
| `test_unordered_functions` | 1.4673 | 1.3613 | -7.2% [-9.5, -5.4] |
| `test_ordered_functions` | 1.4817 | 1.3958 | -5.8% [-8.7, -3.3] |
| `test_ordered_functions_references` | 1.5363 | 1.5647 | +1.8% [+0.1, +4.1] |
| `test_ereg` | 0.2051 | 0.2170 | +5.8% [+3.8, +7.8] |
| `test_rand` | 0.0406 | 0.0519 | +27.8% [+26.5, +29.8] |

Tailcall's aggregate gain therefore coexists with individual regressions. The
largest percentage regression above adds only about 0.011 seconds to its
subtest. The earlier Fibonacci result does not predict the direction of all
function-call benchmarks. Subtest intervals are exploratory and not corrected
for multiple comparisons.

## Workloads and controls

- PHP source: `aefec40ab2f4858bf2abf8cef707837d3e0315c8`, PHP 8.6.0-dev.
- GCC 16.2.0, Ryzen 9 5950X, AlmaLinux 10.2 under WSL2, logical CPU 4.
- Both VMs use ZTS, `-O3`, `-march=x86-64-v3`, full PHP LTO, the five raised
  inlining limits, and 64-byte function alignment described in the
  [earlier VM report](php86-gcc-inlining.md).
- Fresh matching builds add the extensions required by the application,
  including Phar, mbstring, intl, XML/DOM, PDO SQLite, zlib, OpenSSL and pcntl.
  Consequently these binaries differ from the reduced-extension microbenchmark
  builds; absolute timings and code-layout effects should not be compared
  across those build sets.
- OPcache is enabled throughout, with a 256 MB allocation and a 30,000-script
  limit. No Xdebug or PHPStan native accelerator is loaded.
- JIT and its buffer are disabled throughout.
- All timed variants run sequentially with CPU affinity inherited by children.
  Each block contains one hybrid and one tailcall run; order is randomized and
  balanced. Compilation and setup finish before measured runs.

## PHPStan setup

[Symfony Demo](https://github.com/symfony/demo) is pinned to
`8d2e2ef75c3e18df173d8bf2379a14abb58c4c31`. Its unchanged lockfile installs
Symfony FrameworkBundle 8.1.0, PHPStan 2.2.2, phpstan-doctrine 2.0.27 and
phpstan-symfony 2.0.20.

The benchmark runs the project's `phpstan.dist.neon`: level 6, its existing
baseline, Symfony and Doctrine extensions, and the `bin`, `config`, `public`,
`src` and `tests` paths. PHPStan reports **49 analysed project files**. The
generated `config/reference.php` is excluded by upstream's own configuration.
The Symfony development container is warmed before measurement, as required
by its `containerXmlPath` and Doctrine object-manager loader.

The only configuration overrides are an isolated temporary directory and
`parallel.maximumNumberOfProcesses: 1`. PHPStan still uses its ordinary worker
process; debug mode is not enabled. The entire PHPStan temporary directory is
removed before every invocation, including warmups. Thus each run performs
full analysis with fresh result, parser and container caches. The deletion is
outside the measured interval. OS filesystem caches are warmed and not flushed.

Wall time includes the PHPStan command's startup, worker, full analysis and
cache writes. CPU time includes the command and its child. This is a static
analysis workload on a small application, not a Symfony HTTP benchmark or a
large-project PHPStan benchmark.

PHPStan's spawned worker forwards `php_ini_loaded_file()` but does not forward
arbitrary `-d` arguments. A dedicated INI file and an empty `PHP_INI_SCAN_DIR`
ensure matching settings in both parent and worker. A separate bootstrap probe
verifies the worker binary, VM, loaded INI, OPcache and disabled JIT; that probe
is excluded from timed runs. It requires PHPStan's local loopback connection.

There are 12 measured runs per VM after two warmups. Every measured
invocation must exit successfully and produce identical JSON diagnostics with
zero errors. Medians and 95% paired bootstrap intervals use 5,000 resamples.
Intervals describe this host/run and are not adjusted for multiple comparisons.

## PHPBench setup

The installed Phoronix Test Suite is 10.8.4. The official
[`pts/phpbench-1.1.6`](https://openbenchmarking.org/test/pts/phpbench) profile
runs PHPBench 0.8.1 with `-i 2000000`. The archive's SHA256 is verified against
the profile: `32503bd4ace0c8429493de864ca48bb16febed867e52b75f4369d7145f797718`.
The profile and benchmark source are unchanged. All 56 subtests must run for
both VMs, with no regression assertion or fatal error.

An isolated PTS directory disables network communication, anonymous reporting,
result uploads and browser launching. The system PHP runs only the PTS harness;
the profile's supported `PHP_BIN` variable selects a wrapper around the exact
benchmark binary and runtime flags. PTS's automatic system-compiler label may
therefore show the distribution GCC; the benchmark binaries use GCC 16.2.0 as
recorded in their build metadata.

`FORCE_TIMES_TO_RUN=1` permits interleaving VMs between complete official-profile
invocations, instead of grouping all repeats of one VM together. Dynamic repeat
counts and noisy-sample dropping are disabled. Native PTS XML and all subtest
logs are retained. The official score is higher-is-better and equals
`20,000,000 / sum(subtest seconds)`, rounded to an integer; any implied timed
duration uses that reciprocal score rather than the PTS harness's wall time.

PHPBench is a collection of interpreter microbenchmarks. Its score complements
the application workload; it does not measure framework request throughput.

## Reproduction

Compressed evidence accompanies this report:

- [Application build commands, identities, hashes and LTO evidence](results/php86-gcc16-20260922-app-builds.json.gz)
- [PHPStan samples with OPcache enabled](results/php86-gcc16-20260922-app-phpstan.json.gz)
- [PHPBench samples, native PTS XML and all subtest logs](results/php86-gcc16-20260922-app-phpbench.json.gz)
- [PHPBench score and all 56 subtest comparisons](results/php86-gcc16-20260922-app-phpbench-analysis.json.gz)
- [PHPStan parent/worker runtime probes](results/php86-gcc16-20260922-app-worker-probes.json.gz)

Generate `configure` in a clean checkout of the pinned PHP source, then build:

```sh
python3 benchmarks/build-vms.py /path/to/php-src /tmp/php86-app-builds \
    --variants hybrid-tuned tailcall-tuned --application-extensions --jobs 16
```

Check out the pinned Symfony Demo revision, install its locked development
dependencies with `composer install --no-scripts`, and warm its development
container with `php bin/console cache:warmup --env=dev`. Then run:

```sh
python3 benchmarks/benchmark-applications.py /tmp/php86-app-builds/builds.json phpstan \
    --demo /path/to/symfony-demo --output /tmp/phpstan-vms.json \
    --runs 12 --warmups 2 --modes on
python3 benchmarks/prepare-phpbench.py /path/to/existing/pts-user-directory /tmp/php86-pts
python3 benchmarks/benchmark-applications.py /tmp/php86-app-builds/builds.json phpbench \
    --pts /tmp/php86-pts --output /tmp/phpbench-vms.json \
    --runs 8 --warmups 1 --modes on
```

Use a new output path for each matrix. The PTS preparation helper requires an
existing installation of the official profile, including its downloaded archive.
