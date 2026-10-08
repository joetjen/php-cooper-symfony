<?php

declare(strict_types=1);

namespace JOetjen\CooperSymfony\DependencyInjection;

use JOetjen\Cooper\Cooper;
use JOetjen\Cooper\CooperError;
use JOetjen\CooperConfig\ConversionError;
use JOetjen\CooperSymfony\Config\CascEnvResource;
use JOetjen\CooperSymfony\Config\CascGlobResource;
use Symfony\Component\Config\FileLocatorInterface;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\FileLoader;

/**
 * Loads a CASC document into a container: the Config component loader
 * for `.casc` files, which `CooperKernelTrait` registers.
 *
 * ## What the document configures
 *
 * Each top-level block but one is a bundle's configuration, by the
 * bundle's extension alias -- `framework { secret = ${APP_SECRET} }` is
 * what `framework: { secret: '%env(APP_SECRET)%' }` was in
 * `config/packages/framework.yaml` -- handed to the bundle as one
 * configuration array, which its own configuration tree validates as
 * always. The one exception, `parameters { ... }`, sets container
 * parameters: each leaf by its dotted path (`app.locales = [...]` is the
 * parameter `app.locales`), a list being a leaf. Services are not
 * CASC's: `config/services.yaml` and attributes wire them as before.
 *
 * ## When things are read
 *
 * A `${NAME}` written as a value becomes Symfony's `%env(...)%`
 * placeholder (see `EnvPlaceholders`), read whenever the container is
 * used. Everything else resolves now, at build, as Cooper resolves it:
 * imports, `${?NAME}` guards, keys, `@{...}`, `%{...}`, tags, literals.
 * `COOPER_ENV` is the kernel's environment -- the container is built for
 * that one, whatever the process environment says -- so `import
 * "${COOPER_ENV}/*.casc"` reads the overlay of the environment being
 * built.
 *
 * A `%name%` in a string is Symfony's parameter syntax, as in YAML:
 * written in the document, it is resolved by Symfony (`%%` for a
 * literal `%`).
 *
 * ## Freshness
 *
 * Every file the load read, every glob import's expansion and every
 * environment variable read at build become container resources, so a
 * debug container is rebuilt when a CASC file is changed, added where a
 * glob looks, or deleted, or such a variable changes. A missing
 * document configures nothing -- a new application has none yet, and
 * `bin/console cooper:init` needs a container to run -- and is watched
 * for appearing.
 */
final class CascFileLoader extends FileLoader
{
    /** The top-level block holding container parameters rather than a bundle's configuration. */
    public const PARAMETERS = 'parameters';

    /** The options that shape the environment, kept with the env resource to build it again. */
    private const DOTENV_OPTIONS = ['env', 'dotenv', 'dotenvEnv', 'dotenvFiles', 'dotenvDir', 'dotenvOverride'];

    /**
     * @param ContainerBuilder $container the container being built
     * @param FileLocatorInterface $locator finds a document given by a relative or `@Bundle` path
     * @param string|null $env the kernel's environment, `COOPER_ENV` for the load
     * @param array<string, mixed> $options Cooper's load options (`resolvers`, `tags`, `modules`, `.env` options, ...); `cache` and `envValue` are this loader's
     */
    public function __construct(
        ContainerBuilder $container,
        FileLocatorInterface $locator,
        ?string $env = null,
        private readonly array $options = [],
    ) {
        parent::__construct($container, $locator, $env);
    }

    /**
     * Loads the document at `$resource` into the container.
     *
     * @param mixed $resource the document's path
     * @param string|null $type `casc`, or `null` to go by the extension
     * @return null
     * @throws CooperError when the document does not load
     * @throws ConversionError when a value has no form a container holds
     * @throws \InvalidArgumentException when a top-level entry is not a block, or a bundle's alias is unknown
     */
    public function load(mixed $resource, ?string $type = null): mixed
    {
        assert(is_string($resource));
        $path = $this->locate($resource);
        if ($path === null) {
            return null;
        }

        $placeholders = new EnvPlaceholders();
        $options = $this->loadOptions($placeholders);
        $trace = Cooper::loadFileTraced($path, $options);

        $this->track($trace, $options);

        foreach ($placeholders->defaults() as $name => $default) {
            $this->container->setParameter($name, ContainerValues::fromValue($default));
        }
        $modules = is_array($options['modules'] ?? null) ? $options['modules'] : [];
        foreach (ContainerValues::fromDocument($trace->config, $modules) as $key => $value) {
            $this->apply((string) $key, $value, $path);
        }

        return null;
    }

    /**
     * @param mixed $resource the resource
     * @param string|null $type the type the import asked for, if any
     * @return bool whether `$resource` is a CASC document
     */
    public function supports(mixed $resource, ?string $type = null): bool
    {
        if (!is_string($resource)) {
            return false;
        }

        return $type === 'casc' || ($type === null && pathinfo($resource, PATHINFO_EXTENSION) === 'casc');
    }

    /**
     * The document's path, or `null` -- and a resource watching for it
     * -- when an absolute path names no file. Anything else (a relative
     * or `@Bundle` path) is the locator's to find, and to report.
     */
    private function locate(string $resource): ?string
    {
        if (self::isAbsolute($resource) && !file_exists($resource)) {
            $this->container->addResource(new FileExistenceResource($resource));

            return null;
        }
        return $this->locator->locate($resource, null, true);
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('~\A[A-Za-z]:[/\\\\]~', $path) === 1;
    }

    /**
     * Cooper's options for this load: the application's, with the
     * kernel's environment as `COOPER_ENV` (an explicit `env` entry for it
     * still wins), `.env` files read from the project, no cache of
     * Cooper's own (the container is the cache), and `${NAME}` kept a
     * placeholder.
     *
     * @return array<string, mixed>
     */
    private function loadOptions(EnvPlaceholders $placeholders): array
    {
        $options = $this->options;
        $env = is_array($options['env'] ?? null) ? $options['env'] : [];
        if ($this->env !== null) {
            $env += ['COOPER_ENV' => $this->env];
        }
        $options['env'] = $env;
        if (!isset($options['dotenvDir']) && $this->container->hasParameter('kernel.project_dir')) {
            $projectDir = $this->container->getParameter('kernel.project_dir');
            if (is_string($projectDir)) {
                $options['dotenvDir'] = $projectDir;
            }
        }
        $options['cache'] = false;
        $options['envValue'] = $placeholders;
        // `!build(${NAME})`: the value at build, for an option Symfony
        // takes no placeholder for. The tag itself is the identity --
        // `EnvPlaceholders` has no processor for it, so the reference
        // inside is read, and the tag applied to what was read. An
        // application's own `build` tag wins, as any consumer tag does.
        $tags = is_array($options['tags'] ?? null) ? $options['tags'] : [];
        $options['tags'] = $tags + [EnvPlaceholders::BUILD_TAG => static fn (mixed $value): mixed => $value];

        return $options;
    }

    /**
     * Every file, glob expansion and variable the load depended on, as
     * the container's resources.
     *
     * @param array<string, mixed> $options
     */
    private function track(\JOetjen\Cooper\LoadTrace $trace, array $options): void
    {
        foreach ($trace->files as $file) {
            $this->container->addResource(new FileResource($file));
        }
        foreach ($trace->globs as $glob) {
            $this->container->addResource(new CascGlobResource($glob));
        }
        if ($trace->env !== []) {
            $this->container->addResource(new CascEnvResource($trace->env, array_intersect_key($options, array_flip(self::DOTENV_OPTIONS))));
        }
    }

    /**
     * One top-level entry: the parameters, or a bundle's configuration.
     *
     * @throws \InvalidArgumentException
     */
    private function apply(string $key, mixed $value, string $path): void
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" must be a block in %s: a top-level entry is a bundle\'s configuration, or "%s"',
                $key,
                $path,
                self::PARAMETERS,
            ));
        }
        if ($key === self::PARAMETERS) {
            foreach (self::flatten($value, '') as $name => $parameter) {
                $this->container->setParameter($name, $parameter);
            }

            return;
        }
        if (!$this->container->hasExtension($key)) {
            $known = array_map(static fn ($extension): string => $extension->getAlias(), $this->container->getExtensions());
            throw new \InvalidArgumentException(sprintf(
                'There is no extension able to load the configuration for "%s" (in %s). Looked for namespace "%s", found "%s".',
                $key,
                $path,
                $key,
                implode('", "', $known),
            ));
        }
        $this->container->loadFromExtension($key, $value);
    }

    /**
     * Parameters by dotted path: a block's leaves, a list (or an empty
     * block) being one.
     *
     * @param array<array-key, mixed> $block
     * @return array<string, mixed>
     */
    private static function flatten(array $block, string $prefix): array
    {
        $out = [];
        foreach ($block as $key => $value) {
            $name = $prefix . $key;
            if (is_array($value) && $value !== [] && !array_is_list($value)) {
                $out += self::flatten($value, "{$name}.");
            } else {
                $out[$name] = $value;
            }
        }

        return $out;
    }
}
