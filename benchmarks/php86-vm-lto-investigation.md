# Investigating the OPcache-enabled regression

The original regression is reproducible, but the aggregate benchmark hid two
machine-code-layout problems. It is not evidence that tailcall dispatch is
inherently slower. This machine is a local Ryzen 9 5950X running AlmaLinux under
WSL2; the Hyper-V identification does not imply cloud hardware.

## Reproduction and isolation

The original GCC 16.2 LTO binaries were retested on logical CPU 4 with JIT disabled
and OPcache enabled. Twelve measured executions of the unmodified `Zend/bench.php`
gave median script totals of 0.2370 s for tailcall and 0.2025 s for hybrid: a 17.0%
regression, consistent with the original result on CPU 0.

The dominant differences were:

| Operation | Tailcall | Hybrid | Difference |
| --- | ---: | ---: | ---: |
| `mandel()` | 52.0 ms | 20.0 ms | +32.0 ms |
| `ary3(2000)` | 43.5 ms | 21.5 ms | +22.0 ms |
| `fibo(30)` | 37.0 ms | 32.0 ms | +5.0 ms |
| `mandel2()` | 28.0 ms | 35.0 ms | -7.0 ms |
| `nestedloop(12)` | 11.0 ms | 24.0 ms | -13.0 ms |

An isolation harness loads the upstream function definitions without running the
top-level suite, warms up each function three times, then measures 30 calls inside
one PHP process. Each function's output is buffered and its checksum compared
across builds. Six interleaved samples per binary gave:

| Operation, 30 calls | Tailcall wall / CPU time | Hybrid wall / CPU time |
| --- | ---: | ---: |
| `mandel()` | 1.54556 / 1.54554 s | 0.60198 / 0.60197 s |
| `ary3(2000)` | 1.30809 / 1.30806 s | 0.58135 / 0.58135 s |

The close agreement between CPU and wall time rules out descheduling as the
explanation for these isolated slowdowns. Startup and compilation are outside the
timed region.

## 1. `mandel`: indirect-branch prediction

Cycle sampling attributes almost all the slow case to the specialized double
arithmetic and comparison tailcall handlers. Hardware counters for the entire
isolation process (including its three warmup calls) show:

| Counter | Original tailcall | Hybrid | Tailcall, dispatch-PC experiment |
| --- | ---: | ---: | ---: |
| Cycles | 6.567 billion | 2.585 billion | 2.684 billion |
| Instructions | 6.439 billion | 8.787 billion | 8.176 billion |
| Branch misses | 214.482 million | 0.341 million | 0.336 million |
| Branch miss rate | 27.58% | 0.04% | 0.04% |

The counterfactual experiment inserts four one-byte NOPs before the final indirect
jump of the `ADD_DOUBLE`, `SUB_DOUBLE`, and `MUL_DOUBLE`
`SPEC_TMPVARCV_TMPVARCV_TAILCALL_HANDLER` functions. Existing alignment padding is
used, leaving all function entry addresses, arithmetic, data, and jump targets
unchanged. Their jump PCs move from `0x8cd483`, `0x8cd553`, and `0x8cd633` to four
bytes later. Thirty `mandel()` calls then take about **0.630 s instead of 1.548 s**.

Moving only the ADD handler's jump by four bytes is sufficient: **0.600 s**.
Moving all three by one or two bytes does not cure the slowdown (about 1.57–1.58 s).
The improvement despite executing additional instructions, together with the
collapse in branch misses, establishes a layout-sensitive prediction pathology.
It does not identify the processor's undocumented predictor-index function.

With PHP OPcache still enabled but its optimizer disabled, the original tailcall
`mandel` process retires 23.254 billion instructions and has a 0.06% branch miss
rate. With optimization enabled, typed arithmetic handlers cut that instruction
count to 6.439 billion, but their bad prediction behavior consumes the gain. This
explains why enabling OPcache exposed the regression.

## 2. `ary3`: front-end / decoded-op-cache layout

This case has a different signature: both VMs have about a 0.01% branch miss rate.
The original tailcall executes fewer instructions but spends far more time waiting
for decoded operations. Cycle samples concentrate immediately after the indirect
`zend_binary_ops` call inside `ZEND_ASSIGN_DIM_OP_SPEC_CV_CV_TAILCALL_HANDLER`.

The layout experiment keeps the indirect call, return address, comparison,
handler addresses, and PHP operation unchanged. At `0x9175b7`, it replaces a
six-byte conditional jump to the cold result-copy block with a two-byte jump to
a trampoline in existing padding at `0x9175e8`. The trampoline jumps to the same
original destination, `0x9176c8`. The four freed bytes become NOPs. This result-copy
branch is not taken in the measured array loop.

| Counter / measurement | Original tailcall | Short-branch layout |
| --- | ---: | ---: |
| 30 timed calls | 1.310 s | 0.551 s |
| Cycles | 5.575 billion | 2.352 billion |
| Instructions | 10.464 billion | 10.728 billion |
| Empty micro-op queue cycles | 1.267 billion | 6.104 million |
| Decoded-op-cache misses | 66.053 million | 0.806 million |

The latter counters are AMD's `de_dis_uop_queue_empty_di0` and
`op_cache_hit_miss.op_cache_miss`. Here “op cache” means the CPU's decoded-operation
cache, not PHP OPcache. The instruction-cache miss count was small in both original
builds. This intervention establishes a front-end layout effect rather than extra
PHP work, a different arithmetic implementation, or tailcall stack growth.

## Clean-build mitigation

Rebuilding with `-falign-functions=64` in PHP's CFLAGS and LTO link flags removes
both pathologies without any executable edits:

| Operation, 30 calls | Original tailcall | Aligned tailcall |
| --- | ---: | ---: |
| `mandel()` | 1.5456 s | 0.6026 s |
| `ary3(2000)` | 1.3081 s | 0.5565 s |

Retired instruction counts are essentially unchanged: 6.439 billion for `mandel`
and 10.464 billion for `ary3`. The aligned build has 0.301 million `mandel` branch
misses and 1.936 million `ary3` decoded-op-cache misses. A separate 32-byte
function-alignment trial did not remove the aggregate regression.

For the final comparison, **both tailcall and hybrid were rebuilt with LTO and
64-byte function alignment**, with all other flags matching. Sixteen interleaved
measurements after three warmups on CPU 4 gave OPcache-enabled `bench.php` medians
of **0.188424 s tailcall vs 0.196197 s hybrid** (3.96% less elapsed time), and
`micro_bench.php` medians of **0.936484 s vs 1.003847 s** (6.71% less elapsed time).
The [full results and build flags](php86-vm-lto.md) include OPcache-disabled cases.

The package template now applies this alignment to PHP 8.6+ GCC x86_64 compilation
and linking, alongside PHP-only LTO. The clean aligned tailcall build passed the
fibers, generators, closures, and OPcache test groups: **1,335 passed, 39 skipped,
2 expected failures, 0 unexpected failures**. Configuration checks cover both
architectures and RPM/DEB/APK, including exclusion from library and pre-8.6 flags.

## Reproducing the counters

Extract a definitions-only copy of upstream `Zend/bench.php` once so that PHP
OPcache compiles the original functions normally, rather than evaluating them:

```bash
python3 -c 'from pathlib import Path; import sys; source = Path(sys.argv[1]).read_text(); marker = "$t0 = $t = start_test();"; assert source.count(marker) == 1; Path(sys.argv[2]).write_text(source.split(marker)[0])' \
    /path/to/php-src/Zend/bench.php /tmp/vm-bench-functions.php
```

From this repository, use the included isolation harness:

```bash
perf stat -r 3 -e cycles,instructions,branches,branch-misses -- \
    taskset -c 4 /path/to/php -n -d opcache.enable_cli=1 -d opcache.file_update_protection=0 \
    -d opcache.jit=disable -d opcache.jit_buffer_size=0 \
    benchmarks/probe-vm.php /tmp/vm-bench-functions.php mandel 30

perf stat -r 3 \
    -e cycles,instructions,branches,branch-misses,de_dis_uop_queue_empty_di0,op_cache_hit_miss.op_cache_miss -- \
    taskset -c 4 /path/to/php -n -d opcache.enable_cli=1 -d opcache.file_update_protection=0 \
    -d opcache.jit=disable -d opcache.jit_buffer_size=0 \
    benchmarks/probe-vm.php /tmp/vm-bench-functions.php ary3 30
```

The executable edits above are diagnostic interventions for the exact original
build, not package patches; their addresses are build-specific.
