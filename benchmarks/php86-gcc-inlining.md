# PHP 8.6 GCC: hybrid, tailcall, and LTO inlining

The newer [FrankenPHP and compiler-flag study](php86-frankenphp-flags.md) adds
real Symfony HTTP traffic, reruns all 16 workloads with matching application
builds, and tests additional tailcall flags. All its benchmarks use OPcache.

In the standalone CLI on this x86_64 host, with OPcache enabled and both VMs
using raised inlining limits, tailcall uses **9.7% less time in `bench.php`, 3.8% less in
`micro_bench.php`, 9.1% less in Twig, and 7.6% less in Symfony YAML**. It is not
uniformly faster: Fibonacci is 7.9% slower and the object/method workload is 3.4%
slower; generators have no clear winner. Shared libphp shows an 8.3% tailcall
advantage in Twig and 9.9% in YAML, but 10.7% worse Fibonacci performance.

Increasing the limits is a separate tradeoff. For tailcall it improves Twig by
7.4%, Symfony YAML by 5.8%, and fibers by 19.4%, but slows generators by 7.0% and
`ary3` by 6.1%. Text size grows about 70%. LTO and larger inlining budgets should
not be treated as interchangeable or universally beneficial.

The follow-up [PHPStan on Symfony Demo and Phoronix PHPBench report](php86-application-benchmarks.md)
uses matching builds with application extensions and OPcache enabled throughout.

## Optimized OPcache: CLI

Twenty measured executions per variant/workload after three process warmups,
on logical CPU 4. The sign is elapsed-time change: **negative is faster**.
The last two columns compare raised versus default limits within the same VM.
Times are milliseconds per isolated workload invocation; the upstream suites
use complete-process time. A workload invocation can contain many operations
(see `workloads.php`), so these rows are not directly comparable to one another.

| Workload | Tuned hybrid ms | Tuned tailcall ms | Tailcall change [95% CI] | Hybrid: raised limits | Tailcall: raised limits |
| --- | ---: | ---: | ---: | ---: | ---: |
| `bench.php` | 210.680 | 190.279 | -9.7% [-10.9, -6.9] | +4.5% | +0.0% |
| `micro_bench.php` | 970.508 | 934.085 | -3.8% [-4.1, -3.2] | -2.5% | -0.7% |
| `mandel` | 21.012 | 19.984 | -4.9% [-5.3, -4.4] | +0.1% | +0.0% |
| `mandel2` | 34.406 | 27.858 | -19.0% [-19.2, -18.5] | +0.3% | +0.1% |
| `ary3` | 33.945 | 19.423 | -42.8% [-50.5, -33.0] | +75.2% | +6.1% |
| `nestedloop` | 13.461 | 11.164 | -17.1% [-17.5, -16.7] | +0.0% | +0.0% |
| `fibo` | 32.942 | 35.554 | +7.9% [+7.2, +9.1] | -2.3% | -0.4% |
| `hash2` | 4.206 | 3.869 | -8.0% [-10.6, -6.4] | -3.6% | -4.6% |
| `objects` | 6.193 | 6.403 | +3.4% [+3.0, +4.2] | -1.5% | -7.4% |
| `arrays` | 1.527 | 1.454 | -4.8% [-5.4, -4.4] | -0.7% | -2.0% |
| `strings-json` | 1.362 | 1.314 | -3.5% [-3.9, -3.2] | -2.8% | -4.2% |
| `exceptions` | 1.982 | 1.898 | -4.2% [-5.5, -3.2] | -14.4% | -7.3% |
| `generators` | 3.869 | 3.845 | -0.6% [-1.0, +0.5] | +3.0% | +7.0% |
| `fibers` | 0.739 | 0.698 | -5.5% [-6.5, -4.8] | -17.3% | -19.4% |
| `twig` | 0.920 | 0.836 | -9.1% [-9.5, -8.5] | -3.2% | -7.4% |
| `symfony-yaml` | 5.140 | 4.747 | -7.6% [-8.2, -7.2] | -3.1% | -5.8% |

`ary3` deserves special caution: tuned hybrid has a median absolute deviation
of 18.3%, versus 0.5% in default hybrid and 0.4% in tuned tailcall. Its 11-call
samples range from 0.258 to 0.482 seconds. CPU time follows elapsed time closely,
so descheduling alone does not explain the variation. No samples are dropped.

## Optimized OPcache: shared libphp

Twelve measured executions per variant/workload on CPU 4. The same timing and
percentage conventions apply. This launcher executes PHP from the shared
library used by embedders; it does not measure Go, Caddy or HTTP request handling.

| Workload | Tuned hybrid ms | Tuned tailcall ms | Tailcall change [95% CI] | Hybrid: raised limits | Tailcall: raised limits |
| --- | ---: | ---: | ---: | ---: | ---: |
| `bench.php` | 203.621 | 191.446 | -6.0% [-7.1, -5.3] | +0.8% | -0.1% |
| `micro_bench.php` | 1007.883 | 954.220 | -5.3% [-5.9, -4.6] | -2.3% | -0.4% |
| `mandel` | 20.255 | 19.793 | -2.3% [-3.0, -1.4] | +0.1% | -0.4% |
| `mandel2` | 35.038 | 27.798 | -20.7% [-20.8, -20.2] | +0.2% | +0.0% |
| `ary3` | 19.923 | 18.701 | -6.1% [-8.2, -5.5] | +0.1% | -1.4% |
| `nestedloop` | 12.725 | 11.402 | -10.4% [-16.5, -5.7] | +11.1% | +1.7% |
| `fibo` | 34.834 | 38.549 | +10.7% [+8.0, +12.4] | -0.9% | +3.0% |
| `hash2` | 4.244 | 3.958 | -6.7% [-8.5, -6.0] | -1.8% | -3.9% |
| `objects` | 6.342 | 6.442 | +1.6% [+0.7, +3.0] | -1.0% | +0.0% |
| `arrays` | 1.608 | 1.522 | -5.4% [-7.5, -4.0] | +1.2% | -0.6% |
| `strings-json` | 1.333 | 1.310 | -1.7% [-2.4, -1.0] | -7.6% | -4.9% |
| `exceptions` | 2.120 | 2.077 | -2.0% [-2.5, -1.2] | -5.1% | -1.6% |
| `generators` | 3.787 | 3.857 | +1.8% [+1.4, +2.1] | -1.3% | +4.7% |
| `fibers` | 0.789 | 0.742 | -5.9% [-6.4, -3.8] | -16.1% | -18.3% |
| `twig` | 0.913 | 0.837 | -8.3% [-9.1, -7.3] | -3.9% | -3.1% |
| `symfony-yaml` | 5.325 | 4.799 | -9.9% [-11.0, -8.6] | -0.5% | -1.8% |

The CLI-specific tuned-hybrid `ary3` regression is absent here: changing the
limits changes its median by only about 0.1%. Link context materially changes
the result, so the CLI outlier should not be generalized to shared libphp.

## OPcache controls

Twelve samples per variant/workload/mode on CPU 4. Values compare tuned
tailcall against tuned hybrid within each mode; negative means less elapsed time.
The optimized column comes from the 20-sample primary pass.

| Workload | Optimized OPcache | OPcache, optimizer off | OPcache off |
| --- | ---: | ---: | ---: |
| `bench.php` | -9.7% | -13.1% | -9.8% |
| `micro_bench.php` | -3.8% | -5.0% | -5.3% |
| `mandel` | -4.9% | -20.5% | -21.0% |
| `ary3` | -42.8% | -20.2% | -20.8% |
| `objects` | +3.4% | +0.1% | +2.7% |
| `strings-json` | -3.5% | -1.9% | -1.9% |
| `twig` | -9.1% | -10.1% | -8.6% |
| `symfony-yaml` | -7.6% | -7.6% | -8.4% |

The arithmetic gap depends strongly on PHP optimization. Twig and YAML favor
tailcall in all three modes. This does not establish application throughput:
these are repeated component workloads without HTTP, database or network I/O.

## Second-core check

The two tuned CLI builds were repeated on logical CPU 12, with 12 measured
samples per workload. Negative is tailcall elapsed-time change versus hybrid.

| Workload | Change [95% CI] |
| --- | ---: |
| `bench.php` | -8.8% [-11.5, -6.3] |
| `micro_bench.php` | -3.7% [-4.1, -3.1] |
| `mandel` | -5.0% [-5.5, -4.5] |
| `ary3` | -36.5% [-41.5, -21.2] |
| `objects` | +3.1% [+1.7, +3.9] |
| `strings-json` | -3.8% [-4.6, -2.6] |
| `twig` | -9.1% [-9.7, -8.7] |
| `symfony-yaml` | -6.8% [-8.2, -5.5] |

This reproduces the direction of the main comparisons on another core of the
same processor. It is not an independent CPU architecture or machine sample.

## Why Fibonacci favors hybrid

The **7.9% CLI gap compares the two tuned VMs**, rather than measuring the cost
of raising inlining limits. All four builds already use LTO:

| CLI build | Median ms per `fibo(30)` |
| --- | ---: |
| hybrid-default | 33.723 |
| tailcall-default | 35.686 |
| hybrid-tuned | 32.942 |
| tailcall-tuned | 35.554 |

Tailcall is already 5.8% slower with default limits. Raising the limits improves
hybrid by 2.3%, while tailcall changes by -0.4% (95% CI -1.0% to +0.4%). Thus,
there is no clear CLI tailcall tuning regression. Shared libphp does have a
smaller, separate tuning regression: tailcall takes 3.0% longer (CI +0.3% to
+4.9%), in addition to its preexisting 6.5% VM gap.

The upstream function recursively evaluates `fibo_r($n - 2) + fibo_r($n - 1)`.
One `fibo(30)` requires **2,692,537 calls to `fibo_r()`**, each doing very little
work. Its optimized opcodes retain both recursive calls. VM tailcalls describe
native opcode dispatch; they do not eliminate this PHP recursion. GCC's LTO
inlining limits optimize the interpreter's C code, not these PHP function calls
with JIT disabled.

A focused three-repeat `perf stat` pass uses 70 timed calls for CLI and 60 for
libphp, plus four warmups per process. Tuned tailcall relative to tuned hybrid:

| Counter | CLI change | Shared libphp change |
| --- | ---: | ---: |
| CPU cycles | +8.6% | +6.7% |
| Retired instructions | +2.8% | +2.8% |
| Retired branches | +4.9% | +4.9% |
| Branch mispredictions | -25.7% | -22.6% |

These whole-process counters are diagnostic repetitions, not a replacement for
the balanced timing matrix. They show extra executed work despite improved
branch prediction. CLI instructions per cycle fall from 4.20 to 3.97.

Inspection of the actual tuned CLI binaries identifies concrete differences:

- `i_init_func_execute_data()` keeps the opcode pointer in a global register
  for hybrid. The tailcall call-entry path writes it into the PHP execution
  frame, updates it when skipping `RECV`, and later reloads it. GCC also emits
  a conditional branch for the type-hint check where hybrid uses `cmove`.
- Tailcall's `DO_UCALL`, temporary-result `RETURN`, and leave helper execute
  `push rbp` / `mov rbp,rsp` / `pop rbp` on the hot path. Hybrid executes those
  paths inside `execute_ex`, without per-handler frame-pointer prologues.
  Both builds use the same frame-pointer compiler flags.
- Tailcall return handlers install the synthetic `call_leave_op` in the PHP
  frame and jump to a separate leave helper. Hybrid jumps to its internal
  leave label and saves its existing opcode pointer there.

A 999 Hz cycle profile places about 65% of tailcall samples in call setup,
argument copying, invocation, return and frame cleanup. Together, the source,
assembly and counters support higher call/return and handler-boundary overhead
as the explanation for this call-heavy workload. They do **not** isolate the
contribution of each instruction sequence or rule out an additional layout
effect; no controlled compiler/source change was made to apportion the gap.

## Hardware counters and the array regression

Three `perf stat` repetitions on CPU 4, with five times the primary calibrated
iteration count. These counters cover the entire process, including setup and
four untimed workload calls; they are not counters for just the PHP timed region.
Absolute counts can only be compared within a workload.

| Workload / build | Cycles (billions) | Instructions (billions) | Branch misses (millions) |
| --- | ---: | ---: | ---: |
| ary3 / hybrid-default | 4.407 | 20.471 | 0.270 |
| ary3 / tailcall-default | 4.166 | 18.698 | 0.264 |
| ary3 / hybrid-tuned | 7.516 | 20.824 | 0.286 |
| ary3 / tailcall-tuned | 4.443 | 19.049 | 0.270 |
| fibers / tailcall-default | 3.777 | 10.147 | 46.582 |
| fibers / tailcall-tuned | 3.265 | 9.649 | 23.386 |
| twig / tailcall-default | 3.765 | 9.947 | 9.730 |
| twig / tailcall-tuned | 3.482 | 9.799 | 5.014 |

For hybrid `ary3`, raising limits adds only about 1.7% retired instructions but
about 71% cycles in this counter run. Branch misses remain about 0.01% in all
four builds. This is not the branch-prediction pathology observed in the older
tailcall `mandel` investigation. The higher limits reduce branch misses in the
fiber and Twig workloads, alongside their observed improvement.

A separate exploratory check interleaved 12 samples per combination of hybrid
build and per-process ASLR setting, measuring 33 calls per process:

| Build | ASLR | Median seconds | Min–max seconds | Median absolute deviation |
| --- | --- | ---: | ---: | ---: |
| hybrid-default | off | 0.6376 | 0.6342–0.6571 | 0.4% |
| hybrid-default | on | 0.6364 | 0.6337–0.8577 | 0.2% |
| hybrid-tuned | off | 0.9332 | 0.7907–1.0382 | 3.2% |
| hybrid-tuned | on | 1.2863 | 0.8125–1.4440 | 12.2% |

ASLR changes the severity and variance, but disabling it does not remove the
regression. This is not evidence that ASLR alone causes it. A final focused
three-repeat counter pass, also using 33 calls, records:

| Hybrid build / ASLR | Cycles (billions) | Empty micro-op queue cycles (millions) | Decoded-op-cache misses (millions) |
| --- | ---: | ---: | ---: |
| hybrid-default/aslr-0 | 2.783 | 8.949 | 0.964 |
| hybrid-default/aslr-1 | 2.770 | 7.495 | 0.917 |
| hybrid-tuned/aslr-0 | 3.422 | 265.871 | 14.724 |
| hybrid-tuned/aslr-1 | 3.525 | 301.705 | 16.620 |

The CPU decoded-op cache here is unrelated to PHP OPcache. These AMD events
show about 15–18× as many decoded-op-cache misses and 30–40× as many empty-queue
cycles in the tuned build. Together with nearly unchanged instruction counts,
low branch misses, address sensitivity and the absence of the regression in
shared libphp, this supports a layout-sensitive front-end stall explanation.
It does not identify a particular GCC pass or prove which address/cache mapping
causes it. The varying severity across samples is preserved in the raw data.

## Build flags and SPC linkage

PHP 8.6+ GCC builds now enable LTO independently of the VM setting. Dependency
libraries retain their existing flags. The GCC-only tuning follows the
[reference Dockerfile](https://github.com/henderkes/frankenphp-php86-docker/blob/main/Dockerfile):

| GCC parameter | GCC 16.2 `-O3` default on this host | PHP tuning |
| --- | ---: | ---: |
| `inline-unit-growth` | 40 | 200 |
| `ipa-cp-unit-growth` | 10 | 100 |
| `large-function-growth` | 100 | 1000 |
| `max-inline-insns-auto` | 30 | 500 |
| `max-inline-insns-single` | 200 | 1000 |

LTO already performs interprocedural optimization with default limits; these
parameters increase its inlining/code-growth budget. They do not guarantee a
speedup. [GCC recommends supplying optimization flags at compile and link time](https://gcc.gnu.org/onlinedocs/gcc/Optimize-Options.html#index-flto).

In the installed SPC revision `5a1ddb544f3eeaefe3d500f6e4617e59c6ec9942`,
`src/Package/Target/php/unix.php::makeVars()` constructs:

```text
EXTRA_LDFLAGS = dependency link flags
             + SPC_CMD_VAR_PHP_MAKE_EXTRA_LDFLAGS
             + SPC_CMD_VAR_PHP_MAKE_EXTRA_LDFLAGS_LIBPHP
```

Therefore, `SPC_CMD_VAR_PHP_MAKE_EXTRA_LDFLAGS_LIBPHP` is additive. It does **not**
need another copy of `php_optimization_flags`. PHP's `libphp.la` recipe consumes
the combined `EXTRA_LDFLAGS` and also `EXTRA_CFLAGS`. CLI links receive optimization
flags through `EXTRA_CFLAGS`.

There is a second layer: PHP's libtool drops bare `--param=...` and
`-falign-functions=64` from the compiler flags used for shared-library linking.
A minimal shared-library probe reproduced this. The template now uses
`-Wc,--param=...` and `-Wc,-falign-functions=64` in linker flags, which libtool
unwraps before calling GCC. Compile flags remain ordinary GCC options so they
also work in configure probes. Actual libphp link commands were inspected,
and the VM object files contain GCC LTO bytecode and the requested parameters.

The source used here supports GCC 16 tailcall and hybrid LTO directly. Unlike the
older workaround in the reference Dockerfile, these builds do not compile
`zend_execute` or JIT helpers with `-fno-lto`. Hybrid's configure step reserves
`r14`/`r15` for link-time code generation; tailcall does not use global registers.

## Method

- Local Ryzen 9 5950X, AlmaLinux 10.2 under WSL2, GCC 16.2.0 from `/opt/gcc`.
- PHP commit `aefec40ab2f4858bf2abf8cef707837d3e0315c8`, reporting `8.6.0-dev`.
- Four clean out-of-tree builds: hybrid/default, tailcall/default,
  hybrid/tuned, tailcall/tuned. All use ZTS, `-O3`, `-march=x86-64-v3`, full GCC
  LTO and **64-byte function alignment in both VMs**. The two experimental
  factors are VM selection and the five inlining parameters above.
- CLI and shared libphp are built together with matching extensions. This is a
  reduced extension set, not a full package build. The benchmark uses PIC;
  distribution-specific RPM specs and the package's additional PIE flags are
  not included (GCC still links a PIE executable by default). Full commands,
  compiler identity and binary hashes accompany the measurements.
- `libphp-cli.c` calls the shared library's `do_php_cli()` entry point. This tests
  libphp code generation without a web server. It is not a FrankenPHP HTTP
  throughput benchmark.
- JIT is disabled, including its buffer. Runtime probes assert the VM, ZTS,
  version, loaded extensions and OPcache settings before collecting data.
- Process affinity is pinned; variants run sequentially in balanced, randomized
  blocks. Compilation and correctness tests finish before measurement.
- Upstream `Zend/bench.php` and `Zend/micro_bench.php` are unmodified and measured
  as complete processes. Isolated workloads exclude startup/autoloading and
  perform four untimed calls in each process. Iteration counts are calibrated
  once per workload/mode, then held identical across all variants.
- Every isolated timed call must reproduce the warmup output, and output hashes
  must match between variants. The runner verifies that OPcache caches the
  workload when enabled. Twig uses a filesystem template cache warmed before
  timing; Twig 3.29.0 and Symfony YAML 7.4.18 come from this repository's lockfile.
- Tables use medians. Confidence intervals are 95% paired bootstrap intervals
  for the ratio of medians (5,000 resamples). Raw process order, wall/CPU times,
  checksums, memory data and upstream per-operation output are retained; no
  measured samples are discarded. Intervals describe this run on this host,
  not variation across CPUs, builds or production applications. They are not
  adjusted for multiple comparisons.

`memory_get_peak_usage()` records PHP allocator usage. The raw `ru_maxrss` field
is diagnostic only: the exec process can inherit a launcher high-water mark,
so it is not used here to compare PHP resident memory.

## Code size and correctness

GNU `size` reports the following text sizes (code and read-only data, in bytes).
This includes more than just the hot VM dispatch code; debug information is not
included in these figures.

| Build | CLI text | Shared libphp text |
| --- | ---: | ---: |
| Hybrid, default limits | 13,597,877 | 13,586,763 |
| Tailcall, default limits | 14,097,400 | 14,082,223 |
| Hybrid, raised limits | 23,195,570 | 23,197,328 |
| Tailcall, raised limits | 24,005,921 | 24,012,224 |

The higher limits grow CLI text by **70.6% for hybrid** and **70.3% for tailcall**.
Tailcall text is about 3.5% larger than hybrid with the raised limits.

The upstream fibers, generators, closures and OPcache groups were run against
all four CLI variants and both tuned shared-libphp launchers. Each run had
**1,336 passes, 38 skips, two expected failures and zero unexpected failures**.
The package template passed **144 rendered configuration checks**, covering
GCC/Clang, RPM/DEB/APK, x86_64/aarch64, PHP 8.5/8.6/8.10 and independent VM/LTO
selection. Architecture checks here are template checks; only x86_64 was built
and benchmarked.

## Reproduction

The main matrix contains **3,008 measured executions**, excluding warmups,
calibration and diagnostic runs. Gzip-compressed JSON is stored with this report:

- [Build commands, hashes and LTO evidence](results/php86-gcc16-20260922-builds.json.gz)
- [Engine test counts](results/php86-gcc16-20260922-tests.json.gz)
- [Optimized CLI samples](results/php86-gcc16-20260922-cli-on.json.gz)
- [CLI OPcache controls](results/php86-gcc16-20260922-cli-controls.json.gz)
- [Shared libphp samples](results/php86-gcc16-20260922-libphp-on.json.gz)
- [Second-core samples](results/php86-gcc16-20260922-cli-core12.json.gz)
- [Hardware counters](results/php86-gcc16-20260922-perf.json.gz)
- [ASLR samples](results/php86-gcc16-20260922-aslr.json.gz)
- [AMD front-end counters](results/php86-gcc16-20260922-frontend.json.gz)
- [Fibonacci CLI counters](results/php86-gcc16-20260922-fibo-perf-cli.json.gz)
- [Fibonacci shared-libphp counters](results/php86-gcc16-20260922-fibo-perf-libphp.json.gz)
- [Fibonacci profiles, opcodes and disassembly](results/php86-gcc16-20260922-fibo-investigation.json.gz)

All isolated output hashes match across builds, OPcache modes, cores and link
paths. Final binary hashes were rechecked before archiving. The JSON files retain
all samples, settings and commands, including exact focused counter commands.
Build trees and full compiler/test logs remain locally under
`/tmp/php86-vm-matrix-20260922/`.

Generate `configure` with `./buildconf --force` in a clean checkout of the pinned
PHP commit, then from this repository run:

```sh
python3 benchmarks/build-vms.py /path/to/php-src /tmp/php86-matrix \
    --cc /opt/gcc/bin/gcc --jobs 16
php benchmarks/check-build-flags.php
python3 benchmarks/benchmark-matrix.py /path/to/php-src /tmp/php86-matrix/builds.json \
    --output /tmp/php86-cli.json --cpu 4 --runs 20 --modes on
python3 benchmarks/benchmark-matrix.py /path/to/php-src /tmp/php86-matrix/builds.json \
    --output /tmp/php86-controls.json --cpu 4 --runs 12 --modes noopt \
    --workloads bench.php micro_bench.php mandel ary3 objects strings-json twig symfony-yaml
python3 benchmarks/benchmark-matrix.py /path/to/php-src /tmp/php86-matrix/builds.json \
    --output /tmp/php86-libphp.json --cpu 4 --runs 12 --modes on --sapi libphp
python3 benchmarks/benchmark-matrix.py /path/to/php-src /tmp/php86-matrix/builds.json \
    --output /tmp/php86-core12.json --cpu 12 --runs 12 --modes on \
    --variants hybrid-tuned tailcall-tuned \
    --workloads bench.php micro_bench.php mandel ary3 objects strings-json twig symfony-yaml
python3 benchmarks/perf-matrix.py /tmp/php86-cli.json --output /tmp/php86-perf.json \
    --workloads mandel ary3 objects strings-json fibers twig
python3 benchmarks/perf-matrix.py /tmp/php86-cli.json --output /tmp/php86-fibo-cli.json \
    --workloads fibo --scale 10
python3 benchmarks/perf-matrix.py /tmp/php86-libphp.json --output /tmp/php86-fibo-libphp.json \
    --workloads fibo --scale 10
python3 benchmarks/probe-aslr.py /tmp/php86-cli.json --output /tmp/php86-aslr.json
```

Run correctness tests for each CLI binary and each tuned `libphp-cli` launcher:

```sh
cd /path/to/php-src
php -n run-tests.php -n -j8 -q --offline -p /tmp/php86-matrix/tailcall-tuned/sapi/cli/php \
    Zend/tests/fibers Zend/tests/generators Zend/tests/closures ext/opcache/tests
```

`perf-matrix.py` collects standard hardware counters. The focused front-end
experiment uses `perf stat -r 3 -x,` with
`cycles:u,instructions:u,de_dis_uop_queue_empty_di0:u,op_cache_hit_miss.op_cache_miss:u`,
the recorded `ary3` command with 33 calls, and optionally `setarch x86_64 -R`
before PHP. These PMU events are specific to the test CPU and may not be available
on another host. `setarch` changes ASLR only for the launched process.

Subsequent benchmarks always keep OPcache enabled, as requested. The historical
OPcache-disabled controls above remain recorded but are no longer run by the
benchmark harness. Use `--modes noopt` only to isolate optimization within enabled
OPcache, and `--workloads` or `--variants` to restrict a diagnostic run. Each output path must
be new because the runner retains its function definitions and Twig cache in a
sibling `.work` directory. This build script targets x86_64; another architecture
needs matching architecture flags and hybrid register reservations.
