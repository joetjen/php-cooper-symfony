<?php

declare(strict_types=1);

namespace JOetjen\CooperSymfony\DependencyInjection;

use JOetjen\Cooper\Value\CooperAtom;
use JOetjen\Cooper\Value\CooperInteger;
use JOetjen\Cooper\Value\CooperSecret;
use JOetjen\CooperConfig\ConversionError;
use JOetjen\CooperConfig\Convert;

/**
 * What Cooper loaded, as values a container holds: bundle configuration
 * and parameters are scalars, `null` and arrays, nothing else.
 *
 * php-cooper-config's `Convert` does the measurements -- a byte size is
 * its byte count, a duration its milliseconds, an address its text, a
 * tuple a list -- so a document means the same here as anywhere else
 * Cooper's PHP configuration is read. What is left over is made plain:
 * a placeholder becomes its `%env(...)%` text, an atom (`level = info`)
 * its name, a date or time its CASC text, a big integer its digits.
 * Anything still an object -- a secret kept by `cooper-secrets = keep`,
 * or wrapped by an application's class -- has no place in a container's
 * configuration and is refused, naming where it is.
 *
 * @internal
 */
final class ContainerValues
{
    private function __construct()
    {
    }

    /**
     * @param array<array-key, mixed> $config what Cooper loaded
     * @param array<string, mixed> $modules the `modules` option, for a `cooper-secrets` class name
     * @return array<array-key, mixed>
     * @throws ConversionError when a value has no form a container can hold
     */
    public static function fromDocument(array $config, array $modules = []): array
    {
        $plain = self::plain(Convert::toAppConfig($config, $modules), []);
        assert(is_array($plain));

        return $plain;
    }

    /**
     * One value outside any document -- a default kept in a parameter.
     *
     * @throws ConversionError
     */
    public static function fromValue(mixed $value): mixed
    {
        return self::fromDocument(['value' => $value])['value'];
    }

    /**
     * @param list<array-key> $path
     * @throws ConversionError
     */
    private static function plain(mixed $value, array $path): mixed
    {
        return match (true) {
            is_array($value) => self::walk($value, $path),
            $value === null, is_scalar($value) => $value,
            $value instanceof EnvPlaceholder => $value->expression,
            $value instanceof CooperAtom => $value->name,
            $value instanceof CooperInteger => $value->digits,
            $value instanceof CooperSecret => throw new ConversionError(
                'a secret kept as a secret has no place in a container\'s configuration -- '
                . 'drop "cooper-secrets" from the block (at ' . implode('.', $path) . ')'
            ),
            $value instanceof \Stringable => (string) $value,
            default => throw new ConversionError(
                'a ' . get_debug_type($value) . ' has no place in a container\'s configuration (at ' . implode('.', $path) . ')'
            ),
        };
    }

    /**
     * @param array<array-key, mixed> $items
     * @param list<array-key> $path
     * @return array<array-key, mixed>
     * @throws ConversionError
     */
    private static function walk(array $items, array $path): array
    {
        $out = [];
        foreach ($items as $key => $item) {
            $out[$key] = self::plain($item, [...$path, $key]);
        }

        return $out;
    }
}
