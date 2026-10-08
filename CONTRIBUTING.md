# Contributing to php-cooper-symfony

Thanks for considering a contribution. This document covers what you
need to know before opening an issue or a pull request.

## Getting started

```sh
git clone <this repository>
git clone <php-cooper's repository> ../php-cooper                 # until joetjen/cooper is published
git clone <php-cooper-config's repository> ../php-cooper-config   # until joetjen/cooper-config is published
cd php-cooper-symfony
composer install
composer test
```

This should finish with no failures on a clean checkout. If it does
not, please open an issue before doing anything else, because that is
a bug in its own right.

## Project layout

- `src/CooperBundle.php`: registers the `cooper:*` commands.
- `src/Kernel/CooperKernelTrait.php`: the kernel's `configureContainer()`,
  CASC in place of `config/packages/`.
- `src/DependencyInjection/`: `CascFileLoader` (the `.casc` loader),
  `EnvPlaceholders`/`EnvPlaceholder` (`${NAME}` as `%env()%`) and
  `ContainerValues` (loaded values as container values).
- `src/Config/`: the container resources for glob imports and for
  variables read at build.
- `src/Runtime/CooperRuntime.php`: Cooper's `.env` export, before the
  kernel boots.
- `src/Command/ImportCommand.php`, `src/Import/`: `cooper:import`, made
  up of the YAML preset reader, the value conversion, the
  configuration-tree reference and the line diff.
- `tests/`: PHPUnit, mirroring `src/`. `tests/Support/` holds the test
  kernel, the probe bundle and the scratch project.

## Making a change

1. **Tests first.** Write a failing test whose name says, as a
   sentence, what should happen. Then make the change.
2. **Boot it.** Behaviour a Symfony application sees is tested through
   the test kernel, not by calling the loader by hand.
3. **Keep the mapping honest.** A change to what becomes a placeholder
   updates `README.md`'s table and its limits.
4. **Run the full verification pass before opening a PR:**

   ```sh
   composer run precommit
   ```

5. **Keep documentation current.** Update PHPDoc, `README.md`,
   `CHEATSHEET.md` and `CHANGELOG.md` (`[Unreleased]`) in the same
   commit. Run any example before you document it.

## Reporting bugs

Please include:

- the CASC document, or a minimal excerpt;
- the YAML preset, for `cooper:import`;
- your Symfony version;
- what you expected and what happened, with the full error message.

## License

By contributing, you agree that your contributions will be licensed
under the project's [Apache License 2.0](LICENSE).
