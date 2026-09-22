# Autonomous AArch64 PHP 8.6 benchmark task

Run this task on the native AArch64 machine. Work autonomously for hours if needed. Implement, build, measure, investigate, and return evidence and recommendations; do not stop after a plan, a quick screen, or a noisy first result. Preserve progress across context resets with a durable experiment log, a resumable run manifest, and a list of remaining work.

Use the `static-php/packages` repository revision supplied in the handoff. Read `AGENTS.md`, then these reports and their linked raw artifacts:

- `benchmarks/php86-vm-lto.md` and `benchmarks/php86-vm-lto-investigation.md`;
- `benchmarks/php86-gcc-inlining.md`;
- `benchmarks/php86-application-benchmarks.md`;
- `benchmarks/php86-frankenphp-flags.md`;
- `benchmarks/php86-ipa-bolt.md`.

The objective is to establish, on this actual ARM CPU, which PHP 8.6 GCC VM and compiler profile should be recommended for production: hybrid or tailcall; which inlining, frame-pointer, alignment, locality, pointer-analysis and CPU-tuning choices help; and whether LLVM BOLT provides a worthwhile additional gain. Treat the x86 results as hypotheses, not conclusions that ARM must reproduce.

## Non-negotiable controls

1. **ZTS for every PHP variant. OPcache enabled for every PHP benchmark, probe, controller, test driver and spawned worker. Never run an OPcache-disabled control.** Older reports contain historical OPcache-off commands: do not execute them. Audit PHPT tests before execution and exclude tests that deliberately disable OPcache, documenting those exclusions. Ensure system PHP tools also load OPcache. A PHP invocation that violates this rule is invalid and must not be used as evidence.
2. **LTO enabled for every PHP 8.6 build**, hybrid and tailcall alike. Verify LTO in relevant objects and in actual final CLI/shared-library links; a configure argument or environment variable alone is insufficient. Do not silently compile the VM or JIT helper objects without LTO to make a candidate build.
3. **No compiler PGO**: no GCC/Clang profile-generate/profile-use or AutoFDO experiment. BOLT's own binary instrumentation or sampled execution profile is explicitly in scope. Keep these distinct in the report.
4. **JIT disabled** (`opcache.jit=disable`, buffer size zero) throughout this study. Primary comparisons use the normal OPcache optimizer. Only if needed to explain a regression, a separately labelled optimizer-disabled diagnostic is permissible while OPcache itself remains enabled.
5. All compared builds use identical PHP sources, dependencies, extensions, hardening, application inputs and runtime settings except the explicitly varied factors. Do not remove hardening or enable fast-math to manufacture gains. Use clean out-of-tree builds and fingerprint the resulting binaries and libraries.
6. Keep the work and results local. Do not push, open PRs, publish results, upload to OpenBenchmarking, or contact others. Use isolated working directories; preserve existing user work. Routine local tooling installation, toolchain builds, benchmark-server startup and harness fixes are part of the task. Avoid changing machine-wide security settings or interrupting unrelated workloads.

## Establish and adapt the environment

Record CPU model/implementer/part, core topology and core classes, allowed affinity/cgroup limits, NUMA, caches, RAM, OS/kernel/libc, bare metal versus VM/container, governor/frequency and thermal observations, and versions of GCC, binutils, linker, LLVM/BOLT, Go and perf. Discover available CPUs instead of copying CPU numbers from the x86 scripts. Do not mix different core classes in a paired comparison. Pin the server and load generator to suitable separate cores and verify that the client is not the bottleneck.

Use native GCC 16, preferably the same 16.2.0 revision as the reference when available. If unavailable, build an isolated native GCC 16 toolchain or document a justified exact-version substitution; never silently change the compiler between variants. Prefer PHP upstream commit `aefec40ab2f4858bf2abf8cef707837d3e0315c8` from `https://github.com/php/php-src.git` for the architecture comparison. If it cannot be built correctly on this host, investigate and record the necessary common patch or a separately labelled newer-source cohort; do not mix source revisions within a comparison.

Reuse and adapt `build-vms.py`, `benchmark-matrix.py`, `benchmark-applications.py`, `benchmark-frankenphp.py`, `perf-workloads.py`, `perf_support.py`, `workloads.php`, `libphp-cli.c` and the saved orchestration/profile archives. The current builder is **x86-specific**: fix or override its architecture defaults before running it. In particular, do not pass `-m64`, `-march=x86-64-v3`, `-mtune=znver3`, x86 control-flow protection/TLS options, or `-ffixed-r14/-ffixed-r15` on ARM. Inspect PHP's actual hybrid global-register definitions and configure logic; this pinned source uses `x27`/`x28` on AArch64. Establish and verify the correct compile/LTO register reservations instead of blindly transplanting the x86 workaround. Check ARM TLS, frame-pointer and hardening options against this compiler's documentation and accepted-option output.

Build both standalone CLI and shared `libphp`, including the matching application extension set needed for Symfony, PHPStan, SQLite, XML, intl, mbstring, Phar and the other recorded workloads. Prove `PHP_ZTS`, `ZEND_VM_KIND_HYBRID` or `ZEND_VM_KIND_TAILCALL`, version, extension equality, OPcache operation/cached scripts and disabled JIT in each runtime. Verify the actual library mapped by FrankenPHP and the actual PHP binary used by every PHPStan/PTS child. Abort a measurement if the compiler silently selects another VM.

Verify optimization flags after libtool has transformed the final link, including its `-Wc,` forwarding. `SPC_CMD_VAR_PHP_MAKE_EXTRA_LDFLAGS_LIBPHP` is additive to the general PHP flags in the recorded SPC version; inspect the checked-out implementation rather than duplicating settings by assumption. Ensure flags intended only for PHP do not leak into dependency builds.

## Compiler and VM matrix

Use a staged, documented experiment rather than an uncontrolled Cartesian product. Start with fair hybrid/tailcall pairs at identical settings. Distinguish the VM effect at matched flags from the comparison of the best independently tuned profile for each VM.

Test these inlining budgets in this order: `inline-unit-growth`, `ipa-cp-unit-growth`, `large-function-growth`, `max-inline-insns-auto`, `max-inline-insns-single`:

- compiler `-O3` defaults, recorded from the actual compiler; LTO still on;
- medium: `100 / 100 / 500 / 250 / 500`;
- large, from the original Dockerfile profile: `200 / 100 / 1000 / 500 / 1000`;
- extreme exploratory screen: `400 / 200 / 2000 / 1000 / 2000`.

Compare retained versus omitted frame pointers on both VMs at the important budgets, including **medium + `-fomit-frame-pointer`**. Verify generated AArch64 prologues/epilogues and leaf-frame-pointer behavior instead of assuming the option removes every frame record. Measure code size, build wall/CPU time and build peak memory along with runtime performance.

Screen compiler-default function alignment and 16/32/64/128-byte alternatives where meaningful and accepted on this target. Do not assume the x86 64-byte result transfers. Hold other factors fixed and confirm interactions for finalists. Test `-fipa-reorder-for-locality` and `-fipa-pta` individually against the selected non-PGO baseline; screen their combination if useful. Carry promising changes to a matched hybrid control so that a tailcall-specific conclusion is justified.

Include scheduling tuned for the actual CPU while preserving the distribution ISA baseline, and a separately labelled native-ISA experiment if useful. Keep a portable ARM package recommendation distinct from a host-specific `-mcpu=native` recommendation. Other plausible flags may be tested after checking their semantics and correctness, but justify each experiment, retain one-factor controls, and prioritize confirming application gains over accumulating speculative flags. Record unsupported, ignored and failed options explicitly.

## Required workloads

Run all of the following for the reference VM pairs and the final candidate set; use a representative subset only for initial screening:

1. All 16 existing CLI/component workloads: upstream `Zend/bench.php`, `Zend/micro_bench.php`, mandel, mandel2, ary3, nestedloop, fibo, hash2, objects, arrays, strings-json, exceptions, generators, fibers, Twig and Symfony YAML. Keep upstream algorithms/inputs unchanged. Repeat through the shared-library CLI launcher as a separate matrix. Verify matching outputs/checksums and cached execution. Clearly distinguish internal PHP timing from whole-process timing.
2. PHPStan on Symfony Demo, preserving the recorded level/configuration/baseline, complete source set and dependencies. Prefer Demo commit `8d2e2ef75c3e18df173d8bf2379a14abb58c4c31` and its lockfile. Remove the isolated result cache outside each measured interval. Confirm zero analysis errors and verify OPcache/JIT/VM/ZTS in the parent and analysis worker. Primary measurements must control PHPStan parallelism; any additional multicore analysis is a separate experiment.
3. The **official Phoronix Test Suite `pts/phpbench-1.1.6` profile**, PHPBench 0.8.1 patched2, two million iterations and all 56 subtests. Configure PTS locally with uploads, reporting and network communication disabled. Select the candidate PHP explicitly; enable OPcache in both controller and benchmark processes. Retain native XML, scores and all subtest logs. Do not replace the PTS timing result with an unrelated benchmark named phpbench. A separate counter run may execute the exact payload directly if its exclusion of the PTS controller is explicit.
4. Real Symfony Demo served by **FrankenPHP**, both classic and worker modes, at least the blog and post routes already used. Prefer FrankenPHP commit `9b824f225ea64dd09a52049d96d34ef926af96dd` and the recorded dependencies/Go toolchain where compatible. Use the same executable while swapping matched `libphp` libraries. Check mapped library hashes, worker lifecycle, production environment, fixture database, warmed containers/assets/templates, response contents/status, OPcache and JIT. Suppress deprecation/debug logging consistently outside timed work. Exercise one physical core and four comparable physical cores when available; if the machine has fewer suitable cores, use and report the maximum available. Include a sensible additional concurrency/load sweep to identify saturation. Report throughput and p50/p95/p99 latency with request counts, errors and CPU usage; do not call saturated-load latency a general service latency estimate.

The source archives contain the preceding environment and harness details. Recreate their intent while adapting paths, ports, toolchain locations and CPU allocation. Do not reuse x86 binaries or absolute `/tmp` paths. Fix harness failures and keep going. Avoid quoting the known pre-release ZTS OPcache memory-status bug as actual negative memory usage; independently verify functioning caches and document any remaining reporting defect.

## Hardware counters and regression investigation

Use perf where the host exposes usable counters. Probe capabilities and event semantics first. Collect cycles, instructions, IPC, branches, branch misses/miss rates and available cache/TLB/frontend/backend events appropriate to this ARM PMU. Record perf version, event encodings, units, scope, enabled/running time, multiplexing/scaling and raw output. Split event groups or use additional passes if too many events cannot run concurrently. Do not treat unsupported events as zeros or generic cache misses as instruction-cache misses.

Keep headline timing runs **separate from counter/sampling passes**. For HTTP attach only to the target server and its threads, excluding the load generator and unrelated system activity. Acknowledge counter enable/disable around the measured request window and normalize by completed requests. For CLI/PHPStan document whole-process versus timed-region scope and inheritance into workers. Record CPU versus wall time, context switches, migrations, faults, steal/load and throttling where available. Use reliable alternative accounting when a software counter is clearly invalid.

Investigate every material regression or surprising win, particularly Fibonacci, Mandelbrot/alignment, arrays, fibers and exception handling. Combine paired timing repeats, hardware counters, optimized PHP opcodes, source and disassembly/native sampling. Distinguish PHP function-call overhead, VM handler boundaries, register allocation, branches, code layout, frontend stalls and scheduler noise. Do not infer a specific mechanism solely from a throughput change or a correlation. Recheck key effects on another comparable core and distinguish standalone CLI from the library used by FrankenPHP. If perf access is unavailable, pursue permitted process-scoped/tooling alternatives, record the concrete limitation and continue the timing study without invented counters.

## LLVM BOLT

Perform an actual AArch64 BOLT feasibility/build/profile/rewrite/correctness/measurement experiment, not just a literature discussion. Use an appropriate LLVM release with AArch64 support and record its exact version. Optimize the shared PHP library used by FrankenPHP; also assess standalone CLI where supported. Apply the experiment to the selected tailcall profile and a competitive hybrid profile when viable.

For each BOLT comparison retain three controls: the original candidate, the same candidate prepared for BOLT, and the rewritten prepared binary. Retain symbols and relocation information; check whether disabling GCC block partitioning is necessary on this target/toolchain. Attribute the preparation flags separately from the rewrite. Preserve hardening and investigate relocation/unwind/debug warnings before accepting a result.

Collect representative profiles from multiple Symfony routes and classic/worker operation; validate on held-out requests and PHPStan/PHPBench/component workloads. Keep tuning/training separate from final confirmation. Use supported branch-recording hardware if actually exposed; otherwise try architecture/version-supported instrumentation or a documented cycle-sampling fallback. Do not assume x86 LBR or an x86 instrumentation recipe works on this CPU. Validate nonempty coverage, profile/binary identity, stale-profile counts and flow-quality metrics. Keep per-run profiles distinct and merge intentionally: never let the last process silently overwrite the entire training set. Clean up only the profiler helpers belonging to this experiment.

Explore reasonable block/function reordering and splitting settings with controlled validation, rather than transplanting a single x86 command and declaring BOLT ineffective. Test correctness before performance, including exceptions, closures, generators, fibers and real application requests with OPcache enabled. Verify the measured processes actually load the rewritten artifact: optimizing `libphp` does not optimize a separately linked CLI executable. Use matched shared-library launchers when comparing shared-library PHPStan/PHPBench.

If a configuration crashes or miscompiles, preserve the reproducer and exclude it from recommendations. Investigate a reasonable documented fallback or another suitable tool version. If BOLT cannot safely work on this platform after concrete attempts, document that demonstrated limitation and complete every unaffected experiment; do not fabricate a BOLT speed result.

## Confidence, scheduling and stopping rules

Benchmark variants sequentially in randomized, balanced paired blocks. Do not compile, run correctness suites, train/rewrite BOLT, or run other benchmark matrices during a measurement batch. Warm caches and the machine consistently, separate warmups from samples, keep identical calibrated work across variants, and preserve every observation. Diagnose host contention, thermal drift, CPU migrations and client bottlenecks before merely collecting more noisy samples. Any excluded invalid batch needs an objective documented reason, and its raw data must remain available.

Use a pilot/screening phase to choose finalists, primary metrics, practical effect thresholds and **fixed confirmation sample sizes** before inspecting confirmation outcomes. As initial minimums for finalists, target 30 measured CLI/shared/PHPStan samples per variant, 15 complete PTS runs, and 20 HTTP blocks per case with at least 5 seconds warmup and 20 seconds measurement; increase work/duration/sample sizes based on observed pilot variance. Perform two independently ordered confirmation batches, using another comparable core or separated stable session for one where feasible.

Report paired effect estimates and confidence intervals with the direction and denominator explicit. Handle the number of candidates and primary comparisons with an appropriate multiplicity correction or a prespecified small confirmation family; do not promote a winner selected only from many unadjusted intervals. Resample whole paired blocks/sessions where appropriate, respecting dependence. Plan precision for roughly a one-percentage-point half-width on primary CLI/application effects and two points for HTTP, or better. State the actual achieved precision.

Define practical equivalence margins in advance, initially ±1% for CLI/PHPStan/PHPBench and ±2% for HTTP throughput, alongside latency/regression guardrails. A clear win, a clear regression, or an interval entirely inside the relevant equivalence margin can all be high-confidence outcomes. An interval merely crossing zero is **not** proof of equivalence. Confirm recommended gains across independent batches and explain workload tradeoffs; do not hide a material workload regression in a geometric mean.

Do not stop at the initial sample counts if important VM/flag/BOLT comparisons remain ambiguous. Diagnose the variance, improve the experiment and run the necessary confirmation. Avoid repeatedly peeking at ordinary fixed-sample confidence intervals and stopping when they happen to become significant; use an appropriate sequential method or a prespecified/independent fixed confirmation design. Never keep sampling just to obtain a preferred sign.

You may work for hours and should continue until the required comparisons have decision-grade evidence and the recommended build passes correctness checks. High confidence can support keeping the baseline. If an external condition makes further progress impossible, preserve resumable state, show the concrete blocker and attainable uncertainty, and state what must change; do not claim confidence that the measurements do not support. Routine build failures, missing paths and recoverable harness problems are work to solve, not reasons to return a partial plan.

## Deliverables

Produce an AArch64-specific Markdown report, concise executive recommendation and shareable plots. Include:

- exact source/compiler/library/application revisions and hashes, hardware/OS/toolchain details, effective compile/link flags and verified VM/ZTS/OPcache/JIT settings;
- complete absolute and relative results for matched hybrid/tailcall, independently tuned finalists, all required workloads, HTTP modes/core counts, frame pointers/inlining/alignment/IPA/CPU-tuning experiments and BOLT controls;
- paired intervals, dispersion, sample counts/durations, confirmation design, exclusions, practical-equivalence decisions, counters and code/build resource costs;
- evidence explaining regressions, limits of causal claims, and differences from x86 without assuming portability to every ARM CPU;
- correctness outcomes, unsupported/unsafe configurations, BOLT profile quality and any native profiling/unwind tradeoffs;
- separate portable-package and machine-specific recommendations, exact recommended flags, and whether to choose hybrid/tailcall or adopt BOLT for each workload category;
- compressed raw observations, manifests, logs, profiles, source changes, reproducible commands and checkpoint state. Make the report traceable to those artifacts; do not leave its only evidence in ephemeral paths.

Keep general-purpose harness adaptations local and reviewable. Do not silently change production package defaults based on one machine; prepare any suggested template patch separately and explain its scope. Return actual completed findings and artifact paths, not an offer to run the study later. Do not publish them without a new explicit instruction.

## Primary technical references

Verify behavior against the installed versions and source, consulting the [GCC AArch64 options](https://gcc.gnu.org/onlinedocs/gcc/AArch64-Options.html), [GCC optimization options](https://gcc.gnu.org/onlinedocs/gcc/Optimize-Options.html), [LLVM BOLT guide](https://github.com/llvm/llvm-project/blob/main/bolt/README.md), and [perf stat control protocol](https://github.com/torvalds/linux/blob/master/tools/perf/Documentation/perf-stat.txt) as needed.
