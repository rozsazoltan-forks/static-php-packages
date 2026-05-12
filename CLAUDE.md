# Packages repo

Builds RPM/DEB/APK packages (PHP toolchain, gcc, zig) via GitHub Actions, using reproducible Dockerfile-driven builders.

## Layout

- `bin/spp` — main PHP build CLI. Usage in workflows: `php bin/spp build --type=rpm --debuginfo --phpv=8.4 --libs-only` and `php bin/spp all --type=rpm --debuginfo --phpv=8.4 [--iteration=N] [--packages=…]`. `--libs-only` produces a `buildroot/` that gets passed to the next stage as a tarball; `all` consumes that buildroot and produces packages in `dist/<type>/`.
- `bin/createrepo_static`, `bin/forgejo-helper` — repo-side tools.
- `src/` — PHP source (`src/Command`, `src/step`, `src/package`, `src/util`, `src/patches`, `src/ini`, `CraftConfig.php`, `extension.php`, `package.php`).
- `craft.yml` — top-level static-php-cli config: extensions list, build options, SPC_* env (CFLAGS, LDFLAGS, etc.). When toolchain/optimization questions come up, look here before guessing.
- `Dockerfile.{rhel,debian,alpine}` — builders. Built by `.github/workflows/build-images.yml`.
- `.github/workflows/`:
  - `build-rpm-modular-packages.yml` — Alma 8/9/10 × x86_64/arm64 × PHP 8.2/8.3/8.4/8.5. Uploads via rsync+SSH then runs `createrepo_c` on the remote.
  - `build-deb-forgejo.yml`, `build-apk-forgejo.yml` — Debian/Alpine builds, push to Forgejo.
  - `build-zig-packages.yml`, `build-gcc-deb-packages.yml` — toolchain (zig, gcc) packaging.
  - `build-images.yml` — builds the builder containers (libs-only step exists to save build time; uses thin LTO).
  - `spc-download.yml` — produces the `downloads-tarball` artifact that the build workflows pull via `dawidd6/action-download-artifact`.
  - `zizmor.yml` — workflow security audit.

## Builder containers

`Dockerfile.rhel` is matrix-built per Alma version into `ghcr.io/static-php/packages-builder-rhel-{8,9,10}`. It installs `tar`, `zstd`, `gcc-toolset-15`, `cmake` 3.31, `re2c`, `fpm`, and a prebuilt `php` from `files.henderkes.com`.

When editing `Dockerfile.rhel`, remember the per-Alma branches:

- **Alma 8/9** use `@ruby:3.3` module + `source /opt/rh/gcc-toolset-15/enable`.
- **Alma 10** uses plain `ruby` + `source /usr/lib/gcc-toolset/15-env.source`.
- **Alma 8** has no `re2c` package — built from source (4.3 tarball). 9/10 install from repo.

## PHP toolchain selection

Workflows pick the build toolchain by PHP version:

- PHP **< 8.5** → `gcc`
- PHP **>= 8.5** → `zig`

When parsing or filtering build matrices in jq/bash:

```jq
(if ($php | startswith("8.5")) then "zig" else "gcc" end) as $tc
```

The `build-libs` step builds **two** lib sets per (alma, arch) — one for each toolchain — using `phpv: "8.4"` (gcc) and `phpv: "8.5"` (zig) as canonical triggers. The downstream `build` step then maps each PHP version to its toolchain's buildroot artifact (`buildroot-rpm-alma{V}-{arch}-{gcc|zig}`).

## AlmaLinux 8 tar quirk

**Alma 8 ships GNU tar 1.30** — `tar --zstd` is unsupported (the flag was added in tar 1.31). Pipe through the standalone `zstd` binary instead (it's in `Dockerfile.rhel`):

```bash
# Pack
tar -cf - buildroot | zstd -o buildroot.tar.zst
# Unpack
zstd -dc buildroot.tar.zst | tar -xf -
```

The deb/apk forgejo workflows still use `tar --zstd` — that is **fine**; they don't run on Alma. Don't "fix" them.

## Matrix isolation pattern

Per-Alma failures must not cascade. Pattern used in `build-rpm-modular-packages.yml`:

1. **Fan-out job** uses `strategy.fail-fast: false` and an explicit, parseable `name:` like `Build libs (alma{V} {arch} {toolchain})` or `Build (alma{V} {arch} php{V})`. The name format is the contract — downstream filters parse it.
2. **Filter job** runs with `if: ${{ !cancelled() }}` and `permissions: actions: read`. It calls `gh api repos/$GITHUB_REPOSITORY/actions/runs/$GITHUB_RUN_ID/jobs --paginate --jq …`, filters successful jobs by name regex, sed-extracts the tuple, then jq-builds an `{include: [...]}` matrix and an `any` boolean. Both go to `$GITHUB_OUTPUT`.
3. **Downstream job** does `needs: [filter-job]`, gates with `if: ${{ !cancelled() && needs.filter-job.outputs.any == 'true' }}`, and uses `matrix: ${{ fromJson(needs.filter-job.outputs.matrix) }}`.

Why `!cancelled()` not `success()`: upstream matrix entries are allowed to fail; without `!cancelled()` the filter job wouldn't run at all.

When adding a new downstream stage to any workflow, follow this pattern rather than relying on `needs.*.result` (which collapses across the whole matrix).

## Validating workflow changes locally

Before pushing, validate YAML and simulate the jq filters:

```bash
# YAML syntax
python3 -c "import yaml,sys; yaml.safe_load(open(sys.argv[1]))" .github/workflows/build-rpm-modular-packages.yml

# Simulate the compute-build-matrix jq with a mocked "succeeded" string
FULL_MATRIX='{"php-version":["8.2","8.3","8.4","8.5"],"alma":["8","9","10"],"arch":["x86_64","arm64"]}'
succeeded=$'9 x86_64 gcc\n9 arm64 gcc\n9 x86_64 zig\n9 arm64 zig\n10 x86_64 gcc\n10 arm64 gcc\n10 x86_64 zig'
jq -nc --argjson m "$FULL_MATRIX" --arg s "$succeeded" '
  ($s | split("\n") | map(select(length > 0))) as $set |
  [ $m["php-version"][] as $p | $m.alma[] as $a | $m.arch[] as $r |
    (if ($p | startswith("8.5")) then "zig" else "gcc" end) as $tc |
    select($set | index("\($a) \($r) \($tc)")) |
    {"php-version": $p, alma: $a, arch: $r}
  ]'
```

The Plan/Explore agents can also read large workflow files, but for these targeted edits prefer Read + Edit directly.

## Workflow style conventions

- 4-space indentation throughout (including step bodies).
- Steps written as `-   name:` (3 spaces between dash and key). Match this when adding new steps.
- All third-party `uses:` actions are pinned to commit SHAs with a `# vX` trailing comment. Keep that style; don't switch to floating tags.
- `FORCE_JAVASCRIPT_ACTIONS_TO_NODE24: true` is set in container jobs because the GH-hosted Node in Alma containers is too old.
- `runs-on` for arm64: `ubuntu-24.04-arm` (RPM) or `ubuntu-24.04${{ matrix.arch == 'arm64' && '-arm' || '' }}` (DEB style).
- Matrix arch is `arm64`/`x86_64` in inputs; the RPM tooling wants `aarch64` — the conversion happens in the "Set architecture variables" step (`MATRIX_ARCH` env → `RPM_ARCH`).

## Build inputs

All build-* workflows accept comma-separated overrides via `workflow_dispatch`:

- `php_versions` (e.g., `8.2,8.5`) — empty = all.
- `alma_versions` (RPM only).
- `architectures` (`x86_64,arm64` for RPM/APK; `amd64,arm64` for DEB).
- `packages` — passed through to `bin/spp` as `--packages=…`.
- `iteration` — passed through as `--iteration=…`.
- `debug_tmate` — opens tmate on failure (only after build step; tmate binary is downloaded inline because Alma doesn't ship it).

When the user asks to "rerun for X only", they mean these inputs.

## Operational notes

- Packages are uploaded via `rsync` over SSH to `${{ secrets.DEB_SERVER_IP }}:/mnt/data/{rpm|deb|apk}/${RPM_ARCH}/${TARGET_DIR}/`. RPM `update-repo` job ssh-runs `createrepo_static && createrepo_c --update . && modifyrepo_c …` on the remote.
- RPM signing: GPG key from `secrets.DEB_GPG_PRIVATE_KEY` + passphrase `DEB_GPG_PASSWORD`, loaded into `~/.rpmmacros` and used by `rpmsign --addsign`.
- Cache: composer cache is the only persistent cache (`actions/cache` keyed on `composer.lock`).
- `buildroot-*` artifacts are uploaded with `retention-days: 1` — they're cheap intermediates.

## Don'ts

- Don't switch `tar --zstd` to pipes in the deb/apk workflows — they don't run on Alma.
- Don't skip pinning new actions to SHAs.
- Don't replace the dynamic-matrix filter pattern with `if: needs.X.result == 'success'` — that fails closed for the whole matrix when any entry fails.
- Don't `git push` or `gh pr create` unless explicitly asked.
