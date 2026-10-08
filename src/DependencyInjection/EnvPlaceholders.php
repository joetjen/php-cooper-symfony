<?php

declare(strict_types=1);

namespace JOetjen\CooperSymfony\DependencyInjection;

use JOetjen\Cooper\EnvReference;
use JOetjen\Cooper\Value\CooperSecret;

/**
 * Cooper's `envValue` option for a container build: each `${NAME}`
 * written as a value becomes Symfony's `%env(...)%` placeholder, read
 * when the container is used, never when it is built -- so no value of
 * the build machine's environment ends up in `var/cache`.
 *
 * | CASC | Symfony |
 * |---|---|
 * | `${X}` | `%env(X)%` |
 * | `!int(${X})`, `!float(...)`, `!bool(...)`, `!trim(...)` | `%env(int:X)%`, `float:`, `bool:`, `trim:` |
 * | `${X[]}` | `%env(csv:X)%` |
 * | `${X:default}` | `%env(default:<parameter>:X)%`, the default kept in a generated parameter |
 * | `${X:?"message"}` | `%env(X)%` |
 * | `"... ${X} ..."` | the string, the placeholder inside it |
 *
 * The prefixes combine as written: `!int(${X:8080})` is
 * `%env(int:default:<parameter>:X)%`.
 *
 * `!build(${X})` asks for the value at build, for an option Symfony
 * takes no placeholder for (an array node, an option a bundle reads
 * while the container is built).
 *
 * Everything Symfony has no processor for is resolved here, at build,
 * as Cooper would resolve it -- `${X[0]}`, `${X:+alt}`, any filter, any
 * other tag, a list with a default. Its `%` signs are doubled on the
 * way, so a value read from the environment is never taken for a
 * Symfony parameter reference.
 *
 * @internal
 */
final class EnvPlaceholders
{
    /** The tag that asks for a reference's value at build: `!build(${NAME})`. */
    public const BUILD_TAG = 'build';

    /** Where each generated default parameter's name starts. */
    public const DEFAULT_PARAMETER_PREFIX = 'cooper.env_default.';

    /** The tags Symfony has an env var processor of the same meaning for. */
    private const TAG_PROCESSORS = ['int' => 'int', 'float' => 'float', 'bool' => 'bool', 'trim' => 'trim'];

    /** @var array<string, mixed> generated parameter name => the default it holds */
    private array $defaults = [];

    /**
     * What `$reference` resolves to: a placeholder, or Cooper's own value
     * where Symfony has no placeholder for it.
     *
     * @param EnvReference $reference the `${...}` as written
     * @param \Closure(): mixed $resolve the value Cooper would resolve
     * @return mixed an `EnvPlaceholder`, or a value resolved at build
     */
    public function __invoke(EnvReference $reference, \Closure $resolve): mixed
    {
        $processors = $this->processors($reference);
        if ($processors === null) {
            return self::escape($resolve());
        }

        return new EnvPlaceholder('%env(' . implode(':', [...$processors, $reference->name]) . ')%');
    }

    /**
     * The parameters this build's defaults are kept in, to be set on the
     * container.
     *
     * @return array<string, mixed> parameter name => default
     */
    public function defaults(): array
    {
        return $this->defaults;
    }

    /**
     * The processor prefixes `$reference` maps onto, outermost first,
     * or `null` where Symfony has no placeholder that means the same.
     *
     * @return list<string>|null
     */
    private function processors(EnvReference $reference): ?array
    {
        if ($reference->filters !== [] || $reference->index !== null || $reference->suffix === EnvReference::SUBSTITUTE) {
            return null;
        }
        $processors = [];
        if ($reference->tag !== null) {
            $tag = self::TAG_PROCESSORS[$reference->tag] ?? null;
            // A tag on a list (`!trim(${X[]})`) reads the list, which no
            // single processor chain does the way Cooper does.
            if ($tag === null || $reference->list) {
                return null;
            }
            $processors[] = $tag;
        }
        if ($reference->list) {
            // `csv` on an empty variable is an empty list, not an absent
            // one, so a default would never apply: build time it is.
            if ($reference->suffix === EnvReference::DEFAULT) {
                return null;
            }
            $processors[] = 'csv';
        }
        if ($reference->suffix === EnvReference::DEFAULT) {
            $processors[] = 'default';
            $processors[] = $this->defaultParameter($reference->name, $reference->suffixValue());
        }

        return $processors;
    }

    /**
     * The parameter Symfony's `default` processor reads `$default`
     * from. Named by a hash of the variable and the value, so the same
     * default written twice is one parameter, and a different one is
     * never confused with it.
     */
    private function defaultParameter(string $name, mixed $default): string
    {
        $parameter = self::DEFAULT_PARAMETER_PREFIX . substr(hash('xxh128', $name . "\0" . serialize(self::plain($default))), 0, 16);
        $this->defaults[$parameter] = $default;

        return $parameter;
    }

    /**
     * `$value` with placeholders as their text, so it can be hashed.
     */
    private static function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof EnvPlaceholder => $value->expression,
            $value instanceof CooperSecret => self::plain($value->reveal()),
            is_array($value) => array_map(self::plain(...), $value),
            is_object($value) => $value::class . ':' . ($value instanceof \Stringable ? (string) $value : spl_object_id($value)),
            default => $value,
        };
    }

    /**
     * A value read from the environment at build, with every `%`
     * doubled: Symfony reads `%name%` in configuration as a parameter
     * reference, which a password with two `%` signs would become.
     */
    private static function escape(mixed $value): mixed
    {
        return match (true) {
            is_string($value) => str_replace('%', '%%', $value),
            $value instanceof CooperSecret => new CooperSecret(self::escape($value->reveal())),
            is_array($value) => array_map(self::escape(...), $value),
            default => $value,
        };
    }
}
