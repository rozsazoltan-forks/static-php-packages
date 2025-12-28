# SPC Packages

Utility for building and packaging ZTS builds of PHP and shared extensions.
Published daily at https://pkg.henderkes.com. Uses [static-php-cli](https://github.com/crazywhalecc/static-php-cli)

## Usage for users:

```sh
curl -fsSL https://files.henderkes.com/install.sh | sh -s 8.5
```

## Requirements

- PHP 8.5
- ruby
- [fpm (gem)](https://github.com/jordansissel/fpm)
- [nfpm](https://github.com/goreleaser/nfpm)
- rpmbuild (for creating RPM packages)

## Usage for developers

The main command-line tool is `bin/spp`, which uses Symfony Console for command-line parsing and provides several commands:

### Build and Package

To run both build and package steps in one command:

```
php bin/spp all
```

### Parameters

- `--target`: Specify the target architecture using a target triple that Zig understands, such as `native-native-gnu` or `native-native-musl -dynamic`.
- `--type`: Specify the package type to build. rpm, deb or apk. Required.
- `--prefix`: Optional prefix for binaries and packages. `--prefix="-zts85"` generates `php-zts85` and `php-zts85-cli`.

## Output

The build process produces:

- RPM packages in `dist/rpm/`
- DEB packages in `dist/deb/`
- APK packages in `dist/apk/`

## Links

- [static-php-cli](https://github.com/crazywhalecc/static-php-cli)
- [Static PHP Website](https://static-php.dev)
- [Henderkes PHP (ZTS) Packages](https://pkg.henderkes.com)
