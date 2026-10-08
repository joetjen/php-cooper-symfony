# php-cooper-symfony cheatsheet

## Install (manual)

```sh
composer require joetjen/cooper-symfony
```

```php
// config/bundles.php
JOetjen\CooperSymfony\CooperBundle::class => ['all' => true],

// src/Kernel.php
use MicroKernelTrait, CooperKernelTrait {
    CooperKernelTrait::configureContainer insteadof MicroKernelTrait;
}
```

```json
// composer.json, then: composer dump-autoload
"extra": { "runtime": { "class": "JOetjen\\CooperSymfony\\Runtime\\CooperRuntime", "disable_dotenv": true } }
```

```sh
bin/console cooper:import --all           # config/packages/*.yaml -> *.casc
rm config/packages/*.yaml; rm -r config/packages/*/
bin/console cache:clear
```

## Layout

| File | Holds |
|---|---|
| `config/config.casc` | the document: `import "packages/*.casc"`, `import "${COOPER_ENV}/*.casc"` |
| `config/packages/<bundle>.casc` | `<bundle> { ... }`, one bundle's configuration |
| `config/<env>/*.casc` | that environment's overlay (`dev`, `test`, `prod`) |
| `config/services.yaml` | services, unchanged |
| `config/routes.yaml`, `config/routes/` | routes, unchanged |

## The document

```casc
framework { secret = ${APP_SECRET} }        # loadFromExtension('framework', [...])
parameters { app.page_size = 20 }           # %app.page_size%
probe.path = "%kernel.project_dir%/var"     # %...% is Symfony's; %% is a literal %
```

## `${NAME}` at runtime

| CASC | Symfony |
|---|---|
| `${X}` | `%env(X)%` |
| `!int(${X})` / `!float` / `!bool` / `!trim` | `%env(int:X)%` / `float:` / `bool:` / `trim:` |
| `${X[]}` | `%env(csv:X)%` |
| `${X:d}` | `%env(default:cooper.env_default.<hash>:X)%` |
| `${X:?"msg"}` | `%env(X)%` |
| `"a ${X} b"` | `"a %env(X)% b"` |
| `!build(${X})` | the value at build |

At build: imports, `${?X}` guards, keys, `@{}`, `%{}`, other tags,
`${X[0]}`, `${X:+alt}`, filters (`%` escaped). In debug mode, changing
any of these rebuilds the container.

## `.env` (CooperRuntime)

`.env` < `.env.<COOPER_ENV>` < `.env.local` < the real environment. The
environment's name comes from `--env` or the `env` option, else the
real `COOPER_ENV`/`APP_ENV`, else the files, else `dev`. `APP_ENV` is
set from it when unset. Not read: `.env.<env>.local` and
`.env.local.php`.

## Commands

```sh
bin/console cooper:init                   # scaffold config/
bin/console cooper:check                  # does it load?
bin/console cooper:import twig            # one preset
bin/console cooper:import --all --force   # every preset, overwrite changes
bin/console cooper:import twig --reference  # + every option, commented
```

No `cooper:cache:clear`: the container cache replaces php-cooper-config's
compiled cache, so `bin/console cache:clear` is the one to run.

## Kernel hooks

```php
protected function cooperOptions(): array { return ['resolvers' => [...], 'tags' => [...]]; }
protected function cooperDocument(): string { return $this->getProjectDir() . '/config/config.casc'; }
```
