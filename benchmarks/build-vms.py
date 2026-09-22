#!/usr/bin/env python3
"""Build an x86_64 PHP 8.6 GCC VM/inlining matrix, including shared libphp."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess
import time

TUNING = ('--param=inline-unit-growth=200 --param=ipa-cp-unit-growth=100 '
          '--param=large-function-growth=1000 --param=max-inline-insns-auto=500 '
          '--param=max-inline-insns-single=1000')
BASE_CFLAGS = ('-fPIC -O3 -pipe -fno-plt -fno-semantic-interposition '
               '-fstack-clash-protection -fno-omit-frame-pointer -momit-leaf-frame-pointer '
               '-ffunction-sections -fdata-sections -mtls-dialect=gnu2 -m64 '
               '-fcf-protection -march=x86-64-v3 -D_FORTIFY_SOURCE=3 -g -fno-math-errno')
BASE_LDFLAGS = '-Wl,-z,relro -Wl,--as-needed -Wl,-z,now -Wl,-z,noexecstack -Wl,--gc-sections -Wl,--build-id=sha1'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('source', type=Path, help='clean PHP source with configure generated')
    parser.add_argument('output', type=Path, help='new directory for builds and logs')
    parser.add_argument('--cc', default='/opt/gcc/bin/gcc')
    parser.add_argument('--jobs', type=int, default=16)
    parser.add_argument('--cflags', default=BASE_CFLAGS)
    parser.add_argument('--ldflags', default=BASE_LDFLAGS)
    parser.add_argument('--variants', nargs='+',
                        choices=['hybrid-default', 'tailcall-default', 'hybrid-tuned', 'tailcall-tuned'],
                        default=['hybrid-default', 'tailcall-default', 'hybrid-tuned', 'tailcall-tuned'])
    parser.add_argument('--application-extensions', action='store_true',
                        help='include extensions needed by Symfony Demo and PHPStan')
    parser.add_argument('--profiles', type=Path,
                        help='JSON list of {name, vm, optimization}; overrides --variants')
    args = parser.parse_args()
    source, root = args.source.resolve(), args.output.resolve()
    root.mkdir(parents=True, exist_ok=False)
    compiler = Path(args.cc).resolve()
    env = dict(os.environ, PATH=f'{compiler.parent}:{os.environ["PATH"]}', CC=str(compiler),
               CXX=str(compiler.parent / 'g++'), AR=str(compiler.parent / 'gcc-ar'),
               NM=str(compiler.parent / 'gcc-nm'), RANLIB=str(compiler.parent / 'gcc-ranlib'))
    common = [str(source / 'configure'), '--disable-all', '--enable-cli', '--disable-cgi',
              '--disable-phpdbg', '--disable-debug', '--enable-zts', '--enable-embed=shared',
              '--disable-zend-signals', '--enable-zend-max-execution-timers', '--enable-pic',
              '--enable-rtld-now', '--enable-re2c-cgoto', '--disable-rpath', '--with-valgrind=no',
              '--without-pear', '--enable-ctype', '--enable-filter', '--enable-tokenizer',
              '--enable-session', '--with-iconv']
    if args.application_extensions:
        common += ['--enable-phar', '--with-zlib', '--enable-mbstring', '--enable-intl',
                   '--with-libxml', '--enable-dom', '--enable-simplexml', '--enable-xml',
                   '--enable-xmlreader', '--enable-xmlwriter', '--enable-fileinfo',
                   '--enable-pdo', '--with-pdo-sqlite', '--with-sqlite3', '--enable-posix',
                   '--enable-pcntl', '--with-openssl']
    metadata = {
        'source_commit': subprocess.check_output(['git', '-C', str(source), 'rev-parse', 'HEAD'], text=True).strip(),
        'source_status': subprocess.check_output(['git', '-C', str(source), 'status', '--porcelain'], text=True),
        'compiler': subprocess.check_output([str(compiler), '--version'], text=True),
        'gcc_parameter_defaults': subprocess.check_output([str(compiler), '-Q', '-O3', '--help=params'], text=True),
        'jobs': args.jobs, 'builds': {},
    }
    profiles = (json.loads(args.profiles.read_text()) if args.profiles else
                [{'name': name, 'vm': name.split('-')[0],
                  'optimization': '-flto -falign-functions=64' +
                    (' ' + TUNING if name.endswith('-tuned') else '')}
                 for name in args.variants])
    for profile in profiles:
        if profile['vm'] not in ('hybrid', 'tailcall') or not profile['name'].startswith(profile['vm'] + '-'):
            raise ValueError('Each profile name must start with its VM kind')
        if '-flto' not in profile['optimization'].split() or '-fno-lto' in profile['optimization'].split():
            raise ValueError('Every PHP 8.6 profile must enable LTO')
    metadata['profiles'] = profiles
    for profile in profiles:
        vm = profile['vm']
        name = profile['name']
        build = root / name
        build.mkdir()
        optimization = profile['optimization']
        link_optimization = ' '.join('-Wc,' + flag if flag != '-flto' else flag
                                     for flag in optimization.split())
        # configure invokes GCC directly: reserve registers for hybrid LTO there;
        # use libtool forwarding only when running make.
        build_env = dict(env, CFLAGS=f'{args.cflags} {optimization}',
                         LDFLAGS=f'{args.ldflags} {optimization}')
        configure = common + [f'--{"disable" if vm == "tailcall" else "enable"}-gcc-global-regs']
        start = time.monotonic()
        with (root / f'{name}-configure.log').open('w') as log:
            subprocess.run(configure, cwd=build, env=build_env, stdout=log, stderr=subprocess.STDOUT, check=True)
        fixed = ' -ffixed-r14 -ffixed-r15' if vm == 'hybrid' else ''
        ldflags = f'{args.ldflags} {link_optimization}{fixed}'
        make = ['make', f'-j{args.jobs}', f'LDFLAGS={ldflags}',
                f'EXTRA_LDFLAGS={ldflags} -Wl,-Bsymbolic-functions',
                f'LIBTOOL=/bin/sh {build}/libtool --preserve-dup-deps']
        print(f'{name}: configured; building', flush=True)
        with (root / f'{name}-build.log').open('w') as log:
            subprocess.run(make, cwd=build, env=build_env, stdout=log, stderr=subprocess.STDOUT, check=True)
        binary = build / 'sapi/cli/php'
        runtime_options = ['-n', '-d', 'opcache.enable=1', '-d', 'opcache.enable_cli=1',
                           '-d', 'opcache.jit=disable', '-d', 'opcache.jit_buffer_size=0']
        identity = subprocess.check_output([str(binary), *runtime_options, '-r',
            'echo json_encode(["version"=>PHP_VERSION,"vm"=>ZEND_VM_KIND,"zts"=>PHP_ZTS]);'], text=True)
        identity = json.loads(identity)
        if identity['vm'] != f'ZEND_VM_KIND_{vm.upper()}' or not identity['zts']:
            raise RuntimeError(f'Unexpected build identity: {identity}')
        runner = build / 'libphp-cli'
        subprocess.run([str(compiler), str(Path(__file__).resolve().with_name('libphp-cli.c')),
                        f'-L{build}/.libs', f'-Wl,-rpath,{build}/.libs', '-lphp', '-o', str(runner)], check=True, env=env)
        shared_identity = subprocess.check_output([str(runner), *runtime_options, '-r', 'echo ZEND_VM_KIND;'], text=True)
        if shared_identity != identity['vm']:
            raise RuntimeError('Shared library VM differs from CLI')
        shared_link = next(line for line in (root / f'{name}-build.log').read_text().splitlines()
                           if line.startswith('libtool: link: ') and ' -shared ' in line and 'libphp.so' in line)
        for flag in optimization.split():
            if flag not in shared_link.split():
                raise RuntimeError(f'libtool dropped {flag} from shared link')
        lto_options = {}
        for obj in ('Zend/.libs/zend_execute.o', 'ext/opcache/jit/.libs/zend_jit_vm_helpers.o'):
            lto_options[obj] = subprocess.check_output(
                ['readelf', '-p', '.gnu.lto_.opts', str(build / obj)], text=True)
            if "'-flto'" not in lto_options[obj]:
                raise RuntimeError(f'VM object does not contain LTO options: {obj}')
        metadata['builds'][name] = {
            'binary': str(binary), 'libphp': str(build / '.libs/libphp.so'),
            'libphp_runner': str(runner), 'shared_link_command': shared_link,
            'libphp_sha256': hashlib.sha256((build / '.libs/libphp.so').read_bytes()).hexdigest(),
            'lto_object_options': lto_options,
            'identity': identity, 'configure': configure, 'cflags': build_env['CFLAGS'],
            'ldflags': ldflags, 'make': make, 'seconds': time.monotonic() - start,
            'sha256': hashlib.sha256(binary.read_bytes()).hexdigest(),
            'size': subprocess.check_output(['size', str(binary), str(build / '.libs/libphp.so')], text=True),
        }
        (root / 'builds.json').write_text(json.dumps(metadata, indent=2) + '\n')
        print(f'{name}: verified {identity["vm"]}; {metadata["builds"][name]["seconds"]:.1f}s', flush=True)


if __name__ == '__main__':
    main()
