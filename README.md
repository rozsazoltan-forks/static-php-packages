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

## Links

- [static-php-cli](https://github.com/crazywhalecc/static-php-cli)
- [Static PHP Website](https://static-php.dev)
- [Henderkes PHP (ZTS) Packages](https://pkg.henderkes.com)

## Docker images for debugging CI failures

docker compose -f compose-debian.yaml run build "bin/spp all --phpv=8.4 --type=deb --debug"
