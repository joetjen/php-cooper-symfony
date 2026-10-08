<?php

declare(strict_types=1);

namespace JOetjen\CooperSymfony\Runtime;

use JOetjen\Cooper\Dotenv;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Runtime\SymfonyRuntime;

/**
 * Symfony's runtime, with Cooper reading the `.env` files: named in the
 * application's `composer.json` as `extra.runtime.class`.
 *
 * Before the kernel exists, Cooper's `Dotenv::export()` puts what the
 * `.env` files say into the process environment -- `.env`,
 * `.env.<COOPER_ENV>`, `.env.local`, from the project directory, never
 * over a variable the real environment already has -- so `%env(...)%`
 * placeholders, `$_SERVER['APP_DEBUG']` and anything else reading the
 * environment find it. Symfony's own `.env` loading is always off: two
 * loaders with two sets of rules would disagree about which file wins.
 *
 * ## The environment's name
 *
 * Symfony's environments are Cooper's: `dev`, `test`, `prod`. Whichever
 * is named first decides both:
 *
 *  1. the runtime's `env` option, or `--env`/`-e` on the command line --
 *     Cooper reads that environment's `.env.<env>`;
 *  2. a real `APP_ENV` or `COOPER_ENV` -- Cooper's fallback reads
 *     `APP_ENV` when `COOPER_ENV` is unset;
 *  3. `COOPER_ENV` or `APP_ENV` from the `.env` files;
 *  4. `dev`.
 *
 * `APP_ENV`, when nothing set it, is set to the `COOPER_ENV` so found.
 * `APP_DEBUG` is read as Symfony reads it, from wherever it now is --
 * the real environment or a `.env` file -- and is on outside `prod`
 * when neither sets it.
 *
 * Every other option is Symfony's own (`project_dir`, `prod_envs`,
 * `env_var_name`, `debug_var_name`, ...); `dotenv_path`,
 * `dotenv_overload` and `dotenv_extra_paths` mean nothing here --
 * Cooper's `.env` layering is fixed (see `JOetjen\Cooper\Dotenv`).
 */
class CooperRuntime extends SymfonyRuntime
{
    /**
     * @param array<string, mixed> $options Symfony's runtime options; `disable_dotenv` is always set
     * @throws \JOetjen\Cooper\CooperError (stage `dotenv`) on a malformed `.env` file
     */
    public function __construct(array $options = [])
    {
        $envKey = is_string($options['env_var_name'] ?? null) ? $options['env_var_name'] : 'APP_ENV';
        $projectDir = is_string($options['project_dir'] ?? null) ? $options['project_dir'] : Dotenv::projectRoot();

        $named = self::namedEnvironment($options);
        $exportOptions = ['dotenvDir' => $projectDir];
        if ($named !== null) {
            // The override layer: `.env.<named>` is the file read, and the
            // name is what `COOPER_ENV` is exported as -- unless a real
            // `COOPER_ENV` says otherwise, which export never overwrites.
            $exportOptions['env'] = ['COOPER_ENV' => $named];
        }
        Dotenv::export($exportOptions);

        if ($named === null && !self::inEnvironment($envKey)) {
            $cooperEnv = Dotenv::env($exportOptions)['COOPER_ENV'];
            putenv("{$envKey}={$cooperEnv}");
            $_ENV[$envKey] = $_SERVER[$envKey] = $cooperEnv;
        }

        $options['disable_dotenv'] = true;
        parent::__construct($options);
    }

    /**
     * The environment the runtime is told to run, before any variable
     * is consulted: its `env` option, else `--env`/`-e` on a command
     * line -- the two places Symfony's runtime itself looks first.
     *
     * @param array<string, mixed> $options
     */
    private static function namedEnvironment(array $options): ?string
    {
        if (is_string($options['env'] ?? null) && $options['env'] !== '') {
            return $options['env'];
        }
        $cli = in_array(\PHP_SAPI, ['cli', 'phpdbg', 'embed'], true) || !isset($_SERVER['QUERY_STRING']);
        if (!isset($_SERVER['argv']) || !$cli || !class_exists(ArgvInput::class)) {
            return null;
        }
        $given = (new ArgvInput())->getParameterOption(['--env', '-e'], null, true);

        return is_string($given) && $given !== '' ? $given : null;
    }

    /**
     * Whether `$name` is set in the process environment, where Symfony
     * looks for it.
     */
    private static function inEnvironment(string $name): bool
    {
        return getenv($name) !== false || isset($_ENV[$name]) || isset($_SERVER[$name]);
    }
}
