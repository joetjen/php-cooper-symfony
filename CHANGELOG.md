# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Planned as `0.1.0`, the first release.

### Added

- `CooperKernelTrait`: a `MicroKernelTrait` kernel's
  `configureContainer()` that loads `config/config.casc` in place of
  `config/packages/*.yaml`, then imports the services the way
  `MicroKernelTrait` does. It is chosen with one `insteadof` line. It
  also offers `importCooperConfiguration()` for a kernel with its own
  `configureContainer()`, and `cooperDocument()`/`cooperOptions()` to
  override.
- `CascFileLoader`, the Config component loader for `.casc`:
  - Each top-level block is one bundle's configuration, still
    validated by the bundle's own tree. `parameters { ... }` holds
    container parameters, by dotted path.
  - `COOPER_ENV` is the kernel's environment for the build.
  - Cooper's typed values arrive as plain values, under
    php-cooper-config's rules.
  - A missing document configures nothing, and the build watches for
    it to appear.
- `${NAME}` stays a runtime `%env()%` placeholder: `!int`/`!float`/
  `!bool`/`!trim` around it become processors, `${X[]}` becomes `csv:`,
  and `${X:default}` becomes `default:` with a generated parameter. A
  placeholder inside a string stays in the string. A secret read from
  the environment stays a placeholder. References that shape the
  document, and anything Symfony has no processor for, are resolved at
  build, with `%` escaped. `!build(${X})` asks for build time
  explicitly.
- Resource tracking: every CASC file read, every glob import's
  expansion (`CascGlobResource`), and every variable read at build
  (`CascEnvResource`, hashes only) make a debug container rebuild when
  they change.
- `CooperRuntime`, set as `extra.runtime.class`: Cooper's
  `Dotenv::export()` runs before the kernel boots, Symfony's own
  `.env` loading is always off, and `APP_ENV` and `COOPER_ENV` are one
  name, taken from `--env`/the `env` option, then the real environment,
  then the files, then `dev`.
- `CooperBundle`: php-cooper-config's `cooper:init` and `cooper:check`
  on `bin/console`, rooted at `%kernel.project_dir%`. Its
  `cooper:cache:clear` is not: the container cache replaces
  php-cooper-config's compiled cache, so that command would clear a
  cache nothing reads; `cache:clear` is the one to run.
- `cooper:import <name> | --all [--reference] [--force]`: converts a
  bundle's YAML preset, its `when@<env>:` sections and
  `config/packages/<env>/<name>.yaml` into `config/packages/<name>.casc`
  and `config/<env>/<name>.casc` with php-cooper-config's `Writer`.
  - `%env()%` placeholders become `${...}`; `%param%` strings are kept.
  - Tags and processors with no CASC form are reported.
  - `config/config.casc` imports `packages/*.casc` exactly once, before
    the environment overlay.
  - `--reference` adds every option of the bundle's configuration
    tree with its default, commented out.
  - An existing file that would change is shown as a diff and left
    alone without `--force`.
