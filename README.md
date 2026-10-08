# php-cooper-symfony

The Symfony integration of [Cooper](https://github.com/joetjen/php-cooper). A
[CASC](https://github.com/joetjen/php-cooper/blob/main/casc/CASC.md) document replaces Symfony's
`config/packages/*.yaml` for **bundle configuration** and **container
parameters**. Cooper reads the `.env` files.

```casc
#@version = 1.0

# config/config.casc
framework {
  secret = ${APP_SECRET}
  session.cookie_secure = "auto"
}

parameters {
  app.admin_email = "admin@example.com"
  app.page_size   = !int(${PAGE_SIZE:20})
}

import "packages/*.casc"
import "${COOPER_ENV}/*.casc"   # config/dev/, config/test/, config/prod/
```

**Service wiring stays Symfony's**: `config/services.yaml`, autowiring
and attributes work as before, and so do routes. This package changes
where the *settings* come from, not how services are built.

`${APP_SECRET}` above is **not** read when the container is built. It
becomes Symfony's `%env(APP_SECRET)%` placeholder, which is read when
the container is used. No value from the build machine's environment
is ever written into `var/cache`.

**Status: 0.1.0, pre-release.** See [CHANGELOG.md](CHANGELOG.md).

## Contents

- [Installation](#installation): manual, step by step
- [The document](#the-document)
- [What stays YAML, and why](#what-stays-yaml-and-why)
- [When a value is read](#when-a-value-is-read): `${NAME}` as `%env()%`
- [`.env` files](#env-files)
- [Commands](#commands)
- [Customising](#customising)
- [Limits](#limits)

## Installation

There is no Flex recipe, and nothing is patched automatically: each
step below is one you make, and can see in your diff. The steps assume
a standard Symfony 6.4, 7.x or 8.x application built on
`MicroKernelTrait` (any `symfony new` skeleton) and PHP 8.2 or later.

### 1. Require the package

```sh
composer require joetjen/cooper-symfony
```

`joetjen/cooper` and `joetjen/cooper-config` come with it.

### 2. Register the bundle

`config/bundles.php`:

```php
return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    // ...
    JOetjen\CooperSymfony\CooperBundle::class => ['all' => true],
];
```

The bundle puts the `cooper:*` commands on `bin/console`. It takes no
configuration of its own.

### 3. Use the kernel trait

`src/Kernel.php`:

```php
namespace App;

use JOetjen\CooperSymfony\Kernel\CooperKernelTrait;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait, CooperKernelTrait {
        CooperKernelTrait::configureContainer insteadof MicroKernelTrait;
    }
}
```

Both traits define `configureContainer()`, and PHP will not choose
between them silently, so the `insteadof` line is required. Cooper's
version loads `config/config.casc` and then imports the services
exactly as `MicroKernelTrait` does (`config/services.yaml` and
`config/services_<env>.yaml`, or their `.php` counterparts). It does
**not** read `config/packages/`.

If your kernel already has its own `configureContainer()`, keep it and
call the trait's loader from it. Only add `insteadof` if you keep the
trait's version:

```php
private function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
{
    $this->importCooperConfiguration($loader, $builder);
    $container->import('../config/services.yaml');
    // ... the rest of your own configuration
}
```

### 4. Let Cooper read `.env`

In your application's `composer.json`:

```json
{
    "extra": {
        "runtime": {
            "class": "JOetjen\\CooperSymfony\\Runtime\\CooperRuntime",
            "disable_dotenv": true
        }
    },
    "config": {
        "allow-plugins": {
            "symfony/runtime": true
        }
    }
}
```

Then run `composer dump-autoload`. This regenerates
`vendor/autoload_runtime.php`, which `public/index.php` and
`bin/console` start from, so that it uses `CooperRuntime`.

`CooperRuntime` turns Symfony's own `.env` loading off whatever
`disable_dotenv` says. Setting `disable_dotenv` anyway records the
intent for whoever reads `composer.json` next. See
[`.env` files](#env-files).

`symfony/dotenv` is now unused at runtime. You can remove it
(`composer remove symfony/dotenv`), unless you still want its
`debug:dotenv` command.

### 5. Move the YAML into CASC

```sh
bin/console cooper:import --all
```

For each `config/packages/<name>.yaml` this writes
`config/packages/<name>.casc`. Each `when@<env>:` section and each
`config/packages/<env>/<name>.yaml` becomes `config/<env>/<name>.casc`.
It also makes `config/config.casc` import `packages/*.casc`, once and
before the environment overlay. If `config/config.casc` does not exist
yet, it is created together with `config/dev/`, `config/test/` and
`config/prod/`. You can also run `bin/console cooper:init` first to
scaffold them.

Read what the command prints. Every line starting `note:` is
something that has no CASC form:

- an `%env(json:...)%`-style placeholder is kept as Symfony's own
  string, which still works;
- a YAML tag (`!tagged_iterator`, `!php/enum`) is left out and must be
  carried over by hand;
- a `services:` or `imports:` key in a packages file belongs in
  `config/services.yaml`.

`--reference` adds every option of the bundle's configuration tree to
each file, with its default and commented out, so all the settings are
in one place:

```sh
bin/console cooper:import framework --reference
```

### 6. Delete the YAML

Once the CASC files say what the YAML said:

```sh
rm config/packages/*.yaml
rm -r config/packages/*/      # the per-environment directories
bin/console cache:clear
```

The kernel no longer reads them, so leaving them only misleads the
next reader. Keep `config/services.yaml`, `config/routes.yaml`,
`config/routes/` and `config/bundles.php`.

### 7. Check it

```sh
bin/console cooper:check          # the document loads
bin/console debug:config framework  # what the bundle received
bin/console debug:container --parameters
```

## The document

`config/config.casc` sits at the project root. Each top-level block is
named by a bundle's extension alias (`framework`, `twig`, `doctrine`,
`monolog`, `security`, ...) and is that bundle's configuration. The
block is passed to the bundle as one configuration array, and the
bundle's own configuration tree validates it exactly as it validated
the YAML. An unknown option, a value of the wrong type, or a block
named after a bundle that is not registered stops the build with
Symfony's own error.

One top-level block is not a bundle: `parameters { ... }` holds
container parameters. Each leaf is named by its dotted path, and a list
counts as a leaf:

```casc
parameters {
  app.admin_email = "admin@example.com"   # %app.admin_email%
  app.locales     = ["en", "de"]          # %app.locales%, a list
  "mailer.from"   = "noreply@example.com" # a quoted key works too
}
```

Every other top-level value must be a block. Cooper's typed values
arrive as plain values, under php-cooper-config's rules:

| CASC | the bundle receives |
|---|---|
| `1KiB` | `1024`, in bytes |
| `2s` | `2000`, in **milliseconds**. A bundle option in seconds needs the number (`ttl = 2`) |
| `10.0.0.1`, `10.0.0.0/8` | the text |
| `(1, "a")` | a list |
| `info` (an atom) | `"info"` |
| `2026-10-06` and other dates and times | their CASC text |
| `*key = ...` (a secret) | the value, or its placeholder |

A `%name%` in a string is Symfony's parameter syntax, the same as in
YAML: `"%kernel.project_dir%/var/data"` is resolved by Symfony. Write
`%%` for a literal `%`. Values read from the environment at build time
have their `%` doubled automatically.

Overlays and per-environment configuration are plain CASC:
`import "${COOPER_ENV}/*.casc"` reads the overlay of the environment
the container is being built for. `COOPER_ENV` is set to the kernel's
environment (`dev`, `test`, `prod`) for the build, so `bin/console
--env=prod cache:warmup` builds with `config/prod/` whatever the shell
says. A glob import that matches no file is an error in CASC
(§5.1), so every environment you build needs at least one file in its
directory. `cooper:init` creates an empty `app.casc` in each.

## What stays YAML, and why

| Stays | Why |
|---|---|
| `config/services.yaml`, `services_<env>.yaml`, `#[Autowire]`, `#[AsCommand]`, ... | Service wiring is Symfony's DI language: service ids, `!tagged_iterator`, `_defaults`, `resource:` scans, `@service` references. CASC is a configuration language and has no forms for these, and a second wiring syntax would split one concern across two languages. |
| `config/routes.yaml`, `config/routes/`, route attributes | Routing is loaded by the router, not by the container's configuration. |
| `config/bundles.php` | It decides which bundles exist before any configuration is read. |
| `config/preload.php`, `public/index.php`, `bin/console` | Bootstrap code, not configuration. |

`config/packages/` is the part that moves. Its files are bundle
settings and parameters, which is exactly what CASC is for.

## When a value is read

A `${NAME}` written as a value becomes a Symfony env placeholder, which
is read at runtime, per request or per command. Changing the
environment changes the value without rebuilding the container:

| CASC | Symfony | Notes |
|---|---|---|
| `${X}` | `%env(X)%` | |
| `!int(${X})` | `%env(int:X)%` | Symfony rejects a non-numeric value |
| `!float(${X})` | `%env(float:X)%` | |
| `!bool(${X})` | `%env(bool:X)%` | both read `true`/`1`/`yes`/`on` and `false`/`0`/`no`/`off`. Symfony also ignores case and surrounding whitespace (`TRUE`, ` Yes `), reads any other non-zero number as true (`2`, `-1`, `0.5`), and reads anything else (`maybe`, empty) as false where Cooper refuses it |
| `!trim(${X})` | `%env(trim:X)%` | |
| `${X[]}` | `%env(csv:X)%` | see the list caveat under [Limits](#limits) |
| `${X:default}` | `%env(default:cooper.env_default.<hash>:X)%` | the default is kept in a generated parameter. Used when `X` is unset **or empty**, as in Cooper |
| `!int(${X:8080})` | `%env(int:default:cooper.env_default.<hash>:X)%` | the prefixes combine |
| `${X:?"message"}` | `%env(X)%` | Symfony's own "not found" error replaces the message |
| `"redis://${HOST}:6379"` | `"redis://%env(HOST)%:6379"` | the placeholder sits inside the string |
| `*password = ${DB_PASSWORD}` | `%env(DB_PASSWORD)%` | a secret read from the environment stays a placeholder |
| `*password = "literal"` | `"literal"` | a secret written in the file is just a value |

A tag counts only when it is written **directly** around the
reference. `!int(${PORT})` is a typed placeholder. `!int(@{port})`,
where `@port = ${PORT}`, hands the tag a placeholder it cannot read,
and the build fails.

**Read when the container is built.** The following resolve once, at
build, the way Cooper always resolves them:

- References that decide the document's **shape**: `import` paths
  (`"${COOPER_ENV}/*.casc"`), `${?NAME}` guards, `${NAME}` in a key.
- `@{...}` variables, `%{...}` config references, `!{...}` resolvers,
  every other tag, and literals.
- A `${NAME}` that Symfony has no processor for: an index
  (`${X[0]}`), a substitute (`${X:+alt}`), a filter (`${X | upcase}`),
  any tag other than the four above (`!duration(${TTL})`), and a list
  with a default (`${X[]:[...]}`).
- `!build(${X})`, which asks for this explicitly: use it for an option
  that takes no placeholder (see [Limits](#limits)).

In debug mode, every variable read at build is a container resource.
Changing one, for example setting the variable behind a `${?FEATURE}`
guard, rebuilds the container just as editing a file does. Only hashes
of the values are kept beside the cache.

Symfony's other processors (`json:`, `file:`, `resolve:`, `key:`,
`url:`, `base64:`, ...) have no CASC form. Write the placeholder as a
string and Symfony reads it as usual: `hosts = "%env(json:HOSTS)%"`.

## `.env` files

`CooperRuntime` runs before the kernel exists. It calls Cooper's
`Dotenv::export()`, which puts what the `.env` files define into the
process environment (`getenv()`, `$_ENV`, `$_SERVER`), where
`%env()%` and the rest of Symfony look. Cooper's layering decides the
values:

1. `.env`
2. `.env.<COOPER_ENV>`, for example `.env.dev` or `.env.prod`
3. `.env.local`
4. the real environment, which always wins: a variable it already has
   is never overwritten

**The environment's name.** Symfony's environment names are Cooper's
(`dev`, `test`, `prod`). The first of these that is set decides both
`APP_ENV` and `COOPER_ENV`:

1. `--env`/`-e` on the command line, or the runtime's `env` option;
2. a real `COOPER_ENV` or `APP_ENV`;
3. `COOPER_ENV` or `APP_ENV` from the `.env` files;
4. `dev`.

When nothing has set `APP_ENV`, it is set to the `COOPER_ENV` found
this way, so Symfony and the CASC document always agree.

**`APP_DEBUG`** is read the way Symfony reads it, from wherever it now
is: the real environment or a `.env` file, which the runtime has just
exported. When neither sets it, debug is on outside `prod`.
`--no-debug` still turns it off.

**Differences from Symfony's own dotenv:**

- `.env.<env>.local` is not read. Use `.env.local`, which is read in
  every environment, including `test`.
- `.env.local.php`, written by `composer dump-env`, is not read. In
  production, set real environment variables or ship the `.env` files.
- Cooper's `.env` parser is `vlucas/phpdotenv`. It handles the usual
  syntax, but Symfony's `$(command)` expansion is not supported.

## Commands

| Command | What it does |
|---|---|
| `cooper:init` | Scaffolds `config/config.casc` and `config/{dev,test,prod}/app.casc`. Refuses to overwrite anything (php-cooper-config's command) |
| `cooper:check [--path=FILE]` | Loads the document and reports its top-level keys. Exits 1 with the error if it does not load. `${...}` is resolved from the current environment here, not as placeholders (php-cooper-config's command) |
| `cooper:import <name>` | `config/packages/<name>.yaml` and its environment variants into CASC, as described in [step 5](#5-move-the-yaml-into-casc) |
| `cooper:import --all` | Every preset in `config/packages/` |
| `cooper:import ... --reference` | Adds every option of the bundle's configuration tree, with its default, commented out. With no preset, writes the reference alone |
| `cooper:import ... --force` | Overwrites a CASC file that would change. Without it, such a file is left alone, the difference is printed, and the command exits 1 |

php-cooper-config's `cooper:cache:clear` is not on `bin/console`. The
loaded configuration lives in Symfony's compiled container, which takes
the place of php-cooper-config's compiled cache, so there is nothing for
that command to clear. `bin/console cache:clear` is the command to run.

What `cooper:import` converts:

| YAML | CASC |
|---|---|
| `'%env(X)%'`, `'%env(string:X)%'` | `${X}` |
| `'%env(int:X)%'`, `float:`, `bool:`, `trim:` | `!int(${X})`, ... |
| `'%env(csv:X)%'` | `${X[]}` |
| `'%env(default:param:X)%'` | `${X:"%param%"}` (and `default::X` becomes `${X:nil}`) |
| `'redis://%env(HOST)%:6379'` | `"redis://${HOST}:6379"` |
| `'%kernel.project_dir%/var'` | unchanged: Symfony resolves it |
| `{}` | `{}`, an empty block, which stays a map in an overlay |
| any other processor | kept as the string, and noted |
| a YAML tag | left out, and noted (`!php/const` is written as its value, and noted) |

Each environment's overlay is its `when@<env>:` section with
`config/packages/<env>/<name>.yaml` merged over it. The YAML files are
never changed.

## Customising

Override these on your kernel:

```php
// Cooper's load options: resolvers, tags, modules, importSchemes, .env options.
protected function cooperOptions(): array
{
    return ['resolvers' => ['vault' => fn (string $path) => $this->vault->read($path)]];
}

// Somewhere other than config/config.casc.
protected function cooperDocument(): string
{
    return $this->getProjectDir() . '/config/app.casc';
}
```

The `.casc` loader is also registered with the kernel's loader
resolver, so a kernel with its own `configureContainer()` can import
further CASC files once it has called `importCooperConfiguration()`:
`$container->import($this->getProjectDir() . '/config/extra.casc')`.

## Limits

- **A placeholder only goes where the bundle accepts one.** Symfony
  refuses a runtime value for an array node (`A dynamic value is not
  compatible with a "PrototypedArrayNode"`) and for options a bundle
  reads while the container is built. Use `!build(${X})`, or write the
  value in CASC. `${X[]}` therefore works for parameters and for options
  declared as scalars or variables, not for a bundle's list options.
- **`csv` is not Cooper's list split.** Symfony splits on `,` only,
  honours CSV quoting, and does not trim. Cooper splits on `,` or `;`
  and trims each item. An empty variable is an empty list to Symfony,
  but unset to Cooper.
- **An empty variable** is `""` to Symfony's `%env(X)%` and unset to
  Cooper. `${X}` with no default fails the Cooper load when `X` is
  empty, but a placeholder is never loaded by Cooper, so the build
  succeeds and the option receives `""`.
- **Lists in overlays replace.** A CASC overlay's list replaces the
  base list. Symfony, merging two YAML files, appends to some list
  options. Write `+key = [...]` in the overlay to append.
- **Durations are milliseconds** (php-cooper-config's rule). For a
  bundle option in seconds, write the number.
- **`cooper-secrets = keep`, or a wrapping class, is refused.** A
  container's configuration holds scalars and arrays, not secret
  objects.
- **A map-valued parameter** cannot be written: `parameters` blocks
  flatten to dotted names. Lists and scalars are fine.

## Development

```sh
composer install
composer test       # PHPUnit
composer analyse    # PHPStan, level 8
composer precommit  # both
```

The tests boot a minimal `MicroKernelTrait` kernel
(`tests/Support/TestKernel.php`) with FrameworkBundle, a probe bundle
and this bundle, against CASC fixtures in a scratch directory. See
[CONTRIBUTING.md](CONTRIBUTING.md).

## License

[Apache License 2.0](LICENSE). Copyright 2026 Jan Oetjen.
