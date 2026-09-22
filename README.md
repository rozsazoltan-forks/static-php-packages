# SPC Packages

Utility for building and packaging ZTS builds of PHP and shared extensions.
Published daily at https://pkg.henderkes.com. Uses [static-php-cli](https://github.com/crazywhalecc/static-php-cli)

## Usage for users:

```sh
curl -fsSL https://files.henderkes.com/install.sh | sh -s 8.5
```

## Usage for developers

The main command-line tool is `bin/spp` with the steps `build` and `package`. The former builds php and extensions, the latter packages them into os-packages.

### Build and Package

To run both build and package steps in one command:

```
php bin/spp all
```

### Parameters

- `--target`: Optional. Specify the target architecture using a target triple that Zig understands, such as `native-native-gnu` or `native-native-musl -dynamic`. Default uses current OS defaults.
- `--type`: Required. Specify the package type to build. rpm, deb or apk.
- `--prefix`: Optional. Prefix for binaries and packages. `--prefix="-zts85"` generates `php-zts85` and `php-zts85-cli`. Defaults to `-zts`.
- `--packages`: Optional. Only build binaries and create packages for those packages. Default empty, builds everything.

### PHP 8.6 VM and LTO

GCC builds of PHP 8.6 and later use `-flto` for PHP compilation and linking,
independently of VM selection, while dependency libraries keep their non-LTO flags.
PHP uses the benchmarked medium GCC inlining limits at compile and link time:
`inline-unit-growth=100`, `ipa-cp-unit-growth=100`, `large-function-growth=500`,
`max-inline-insns-auto=250`, and `max-inline-insns-single=500`. PHP also uses
`-fomit-frame-pointer`, trading frame-pointer-based native profiling for the
measured performance improvement. Libtool's `-Wc,` forwarding preserves these
options in shared libphp links. SPC adds
`SPC_CMD_VAR_PHP_MAKE_EXTRA_LDFLAGS_LIBPHP` to the general PHP linker flags, so
libphp inherits the optimization flags without duplication. On x86_64, tailcall
PHP also uses
`-falign-functions=64` to address the measured tailcall code-layout regression.
`--disable-gcc-global-regs` selects
the tailcall VM on supported compilers. `php bin/spp test --phpv=8.6 --type=rpm`
verifies `ZEND_VM_KIND_TAILCALL` in the installed CLI and in FrankenPHP's HTTP
response, failing if the compiler fell back to another VM.

See the [FrankenPHP and compiler-flag study](benchmarks/php86-frankenphp-flags.md)
for the selected profile, the [locality, pointer-analysis and BOLT study](benchmarks/php86-ipa-bolt.md)
for additional flags and hardware counters, the [GCC VM and inlining comparison](benchmarks/php86-gcc-inlining.md) for
measurements and reproduction instructions, and the
[earlier LTO benchmark](benchmarks/php86-vm-lto.md) for the alignment investigation.
For an independent ARM study, use the [native AArch64 agent task](benchmarks/aarch64-agent-task.md).

## Links

- [static-php-cli](https://github.com/crazywhalecc/static-php-cli)
- [Static PHP Website](https://static-php.dev)
- [Henderkes PHP (ZTS) Packages](https://pkg.henderkes.com)

## Docker images for debugging CI failures

docker compose -f compose-debian.yaml run build "bin/spp all --phpv=8.4 --type=deb --debug"
