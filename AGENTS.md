# AGENTS.md

Instructions for AI agents working in this PHP codebase.

## Communication

- Every response starts with the user's first name. Determine it from `git config user.name` (take the first name); if that's unavailable or ambiguous, ask once. Remember the answer for the rest of the session rather than re-deriving or re-asking.

## Before every commit

- Run `composer run precommit` and make sure it passes. No exceptions. It expands to `vendor/bin/phpstan analyse --level=8` (the paths are in `phpstan.neon`) followed by `phpunit`.

## Tests

- Tests are written first: a failing test, then the change that makes it pass. Test names read as sentences (`testAnEnvironmentReferenceIsReadAtRuntimeNotBakedIntoTheContainer`).
- Tests must stay current with behavior: a change to what code does needs its tests updated in the same commit, not "later".
- Put new tests in the file that matches what you're touching, mirroring `src/`'s own layout under `tests/`. Add a new test file only when a change doesn't fit any existing one.
- Integration tests boot `tests/Support/TestKernel.php` -- FrameworkBundle, the probe bundle, and this bundle, installed as `README.md` says -- in a scratch project (`IntegrationTestCase`), and restore every environment variable they set. A value checked for not being baked into the cache must be one no Symfony source could contain (`zq-...`).

## Documentation

- Treat every documentation surface touched by a change as part of that change: PHPDoc blocks, `README.md`, `CHEATSHEET.md`, `CHANGELOG.md`, and comments explaining non-obvious behavior. Comments explain *why*.
- Every public class needs a class-level PHPDoc block. Every public method needs a `@param`/`@return`/`@throws`-annotated PHPDoc block.
- Before documenting any example or claim about behavior, run it.
- Update `CHANGELOG.md` for every user-facing change, following [Keep a Changelog](https://keepachangelog.com/): entries under `[Unreleased]` as you work, moved under a version heading on release.

## Scope

- CASC replaces Symfony's configuration for **bundle configuration and parameters** only. Service wiring (`config/services.yaml`, attributes) and routing stay Symfony's; do not grow CASC forms for them.
- Installation is manual and documented step by step in `README.md`. No Flex recipe, no code that edits an application's files except the `cooper:*` commands it runs on purpose.
- A `${NAME}` written as a value stays a runtime `%env()%` placeholder; nothing read from the environment may be baked into the container unless it shapes the document or Symfony has no placeholder for it. `README.md`'s mapping table is the contract.
- CASC itself is `joetjen/cooper`'s, conversion and the shared commands `joetjen/cooper-config`'s. A need that looks like either belongs there, with its own tests and docs.

## Static analysis

- `vendor/bin/phpstan analyse --level=8` is part of `composer run precommit` -- keep it clean, with a specific justification for anything that must be ignored, never a blanket one. `tests/Support/TestKernel.php` is analysed too: PHPStan analyses a trait only where a class uses it.

## Coding style

- Follow `.editorconfig` (4-space indent, PSR-12). `declare(strict_types=1)` in every PHP file.
- Target PHP 8.2+ (`composer.json`'s `require.php`) and Symfony 6.4, 7.x and 8.x.
- Classes that are not part of the public API are marked `@internal`.

## Git workflow

- Use [git flow](https://nvie.com/posts/a-successful-git-branching-model/): `main` (releases), `develop` (integration), `feature/*`, `release/*`, `hotfix/*`, `support/*`.
- No direct commits to `main` or `develop`. Branch, then merge/PR back. Base pull requests on `develop` unless you're specifically preparing a release.

## Commits

- Use [Conventional Commits](https://www.conventionalcommits.org/): `<type>[optional scope]: <description>`.
- Breaking changes: `!` after type/scope, or a `BREAKING CHANGE:` footer.

## Versioning

- Use [Semantic Versioning](https://semver.org/). Tags match the changelog entry when tagging a release.

## License

- Apache-2.0, never MIT.
