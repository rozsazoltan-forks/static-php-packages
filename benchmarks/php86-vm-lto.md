# PHP 8.6: tailcall vs hybrid with GCC LTO

Follow-up: [the regression investigation](php86-vm-lto-investigation.md) isolates
the slowdown to branch-prediction and decoded-op-cache layout effects, with
same-executable counterfactual tests.

## Results with 64-byte function alignment

Both binaries use `-flto -falign-functions=64`, with all other flags matching.
Three warmups and 16 interleaved measurements per binary, pinned to logical CPU 4:

| Workload | OPcache | Hybrid + LTO | Tailcall + LTO | Tailcall time change |
| --- | --- | ---: | ---: | ---: |
| `Zend/bench.php` | Off | 0.297867 s | 0.274210 s | -7.94% |
| `Zend/micro_bench.php` | Off | 1.307047 s | 1.229926 s | -5.90% |
| `Zend/bench.php` | On | 0.196197 s | 0.188424 s | -3.96% |
| `Zend/micro_bench.php` | On | 1.003847 s | 0.936484 s | -6.71% |

CPU time agrees closely with wall time: for OPcache-enabled `bench.php`, median
CPU times are 0.187467 s (tailcall) and 0.195243 s (hybrid). No samples were discarded.
Raw samples: `/tmp/opencode/vm-regression-align64-comparison.json` on this host.

The aligned tailcall build passed the upstream fibers, generators, closures, and
OPcache tests: **1,335 passed, 39 skipped, 2 expected failures, 0 unexpected failures**.

## Original results before the layout fix

Median wall time, seconds; lower is better. Time change is
`(tailcall / hybrid - 1) * 100`.

| Workload | OPcache | Hybrid + LTO | Tailcall + LTO | Tailcall time change |
| --- | --- | ---: | ---: | ---: |
| `Zend/bench.php` | Off | 0.324609 | 0.299944 | -7.60% |
| `Zend/micro_bench.php` | Off | 1.318086 | 1.234155 | -6.37% |
| `Zend/bench.php` | On | 0.209956 | 0.246157 | +17.24% |
| `Zend/micro_bench.php` | On | 1.002624 | 0.939139 | -6.33% |

Tailcall wins three of these four cases, but **OPcache-enabled `bench.php` takes
17.24% longer**. This is not a uniform speedup. Its measured ranges do not overlap:
tailcall 0.245667–0.255794 s versus hybrid 0.207509–0.223371 s. Per-operation
medians show `mandel` at 0.052 s versus hybrid's 0.020 s, and `ary3(2000)` at
0.044 s versus 0.021 s; gains elsewhere do not offset those costs.

These results use the final isolated run on logical CPU 0. An earlier timeout/retry
overlapped benchmark processes; those measurements are excluded. Final raw samples
and per-operation output are in `/tmp/opencode/packages-vm-lto-isolated.json` on
the benchmark host.

Runtime probes confirmed `ZEND_VM_KIND_TAILCALL` and `ZEND_VM_KIND_HYBRID`,
respectively. The tailcall build also passed the upstream fibers, generators,
closures, and VM stack extension tests: **432 passed, 3 skipped, 0 failed**.
Full package installation and FrankenPHP HTTP integration were not run locally.

Absolute times from different cores/runs should not be used to calculate the
alignment gain; the controlled same-core experiments are recorded in the investigation.

## Setup

- Host: local AMD Ryzen 9 5950X, x86_64, AlmaLinux 10.2 under WSL2.
- Compiler: `gcc (static-php GCC) 16.2.0`.
- PHP: `PHP-8.6` commit `aefec40ab2f4858bf2abf8cef707837d3e0315c8`, reporting
  `8.6.0-dev`. This branch contains the GCC tailcall and LTO support.
- Both builds: ZTS CLI, `-O3`, `-march=x86-64-v3`, `-flto` at compile and link time,
  matching the package template's AlmaLinux 10 PHP optimization/hardening flags.
- Tailcall: `--disable-gcc-global-regs`; hybrid: `--enable-gcc-global-regs`.
  PHP's configure adds `-ffixed-r14 -ffixed-r15` to hybrid's LTO link flags.
- Minimal CLI builds with PHP's built-in extensions and OPcache; external
  dependency libraries were not rebuilt with LTO.
- Unmodified `Zend/bench.php` and `Zend/micro_bench.php` from that same commit.
- JIT disabled for every run. OPcache tested both disabled and enabled for CLI.
- Each case: three warmups per binary, then 16 measured runs per binary in
  alternating order, pinned to one logical CPU. Measurements are whole-process
  wall-clock times, including CLI startup and script compilation.
- The runner verifies both runtime VM constants, matching PHP versions and ZTS settings,
  and the requested OPcache/JIT state before measuring.

## Reproduction

Build two clean, out-of-tree directories from the same source checkout. On the
AlmaLinux 10 GCC builder, use these shared flags:

```bash
export CC=gcc CXX=g++
export CFLAGS='-fPIC -O3 -pipe -fno-plt -fno-semantic-interposition -fstack-clash-protection -fno-omit-frame-pointer -momit-leaf-frame-pointer -ffunction-sections -fdata-sections -mtls-dialect=gnu2 -m64 -fcf-protection -march=x86-64-v3 -Wp,-U_FORTIFY_SOURCE,-D_FORTIFY_SOURCE=3 -specs=/usr/lib/rpm/redhat/redhat-hardened-cc1 -g -fno-math-errno -fPIE -flto'
export LDFLAGS='-Wl,-z,relro -Wl,--as-needed -Wl,-z,now -Wl,-z,noexecstack -Wl,--gc-sections -specs=/usr/lib/rpm/redhat/redhat-hardened-ld -Wl,-z,pack-relative-relocs -Wl,--build-id=sha1 -pie -flto'
```

For the aligned comparison, append these to **both** builds before configuring:

```bash
export CFLAGS="$CFLAGS -falign-functions=64"
export LDFLAGS="$LDFLAGS -falign-functions=64"
```

Run `./buildconf --force` in the source checkout first. From each build directory,
configure with the following common options, adding
`--disable-gcc-global-regs` for tailcall or `--enable-gcc-global-regs` for hybrid:

```bash
/absolute/path/to/php-src/configure \
    --disable-all --enable-cli --disable-cgi --disable-phpdbg --disable-debug \
    --enable-zts --disable-zend-signals --enable-zend-max-execution-timers \
    --enable-pic --enable-rtld-now --enable-re2c-cgoto --disable-rpath \
    --with-valgrind=no --without-pear --disable-gcc-global-regs
make -j16
```

After both builds finish and the host is idle, run from this repository:

```bash
python3 benchmarks/compare-vms.py /absolute/path/to/php-src \
    /absolute/path/to/tailcall/sapi/cli/php \
    /absolute/path/to/hybrid/sapi/cli/php \
    --runs 16 --warmups 3 --cpu 4 --output /tmp/vm-lto-results.json
```

The JSON contains the binary paths and options, runtime identities, all wall-clock
and CPU-time samples, and the benchmarks' per-operation output and medians.
The runner accepts prebuilt binaries;
LTO is established by the build flags and compiler/link logs, not a PHP runtime API.

These are interpreter microbenchmarks on one x86_64 host, not application throughput
or an arm64 comparison.
