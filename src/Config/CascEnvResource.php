<?php

declare(strict_types=1);

namespace JOetjen\CooperSymfony\Config;

use JOetjen\Cooper\Dotenv;
use Symfony\Component\Config\Resource\SelfCheckingResourceInterface;

/**
 * The environment variables a CASC load read while the container was
 * built -- a `${?NAME}` guard, `${NAME}` in an import path or a key, a
 * `${NAME}` Symfony has no placeholder for -- as a container resource:
 * fresh while each still has the value it had.
 *
 * Only hashes are kept: the resource is serialized next to the cached
 * container, and a variable read at build may well be a secret.
 *
 * The environment is built again the way the load built it -- the same
 * `.env` options, the same override layer -- so a change to a `.env`
 * file counts as much as one to the real environment.
 */
final class CascEnvResource implements SelfCheckingResourceInterface
{
    /** @var array<string, string|null> name => hash of the value, `null` for unset or empty */
    private readonly array $hashes;

    /**
     * @param array<string, string|null> $values name => the value read, `null` for unset or empty (a `LoadTrace`'s `$env`)
     * @param array<string, mixed> $dotenvOptions the load's `.env` options: `env`, `dotenv`, `dotenvEnv`, `dotenvFiles`, `dotenvDir`, `dotenvOverride`
     */
    public function __construct(array $values, private readonly array $dotenvOptions)
    {
        $this->hashes = array_map(self::hash(...), $values);
    }

    /**
     * @return string the variables, by name
     */
    public function __toString(): string
    {
        // Symfony caches a resource's freshness by this string for the
        // rest of the process, so two different states must never share
        // one: the values' hashes and the options are part of it.
        return 'casc-env:' . implode(',', array_keys($this->hashes)) . ':' . hash('xxh128', serialize([$this->hashes, $this->dotenvOptions]));
    }

    /**
     * @param int $timestamp unused: an environment has no modification time
     * @return bool whether every variable still has the value it had
     */
    public function isFresh(int $timestamp): bool
    {
        try {
            $env = Dotenv::env($this->dotenvOptions);
        } catch (\Throwable) {
            // A `.env` file that no longer parses: rebuilding reports it.
            return false;
        }
        foreach ($this->hashes as $name => $hash) {
            $value = $env[$name] ?? '';
            if (self::hash($value === '' ? null : $value) !== $hash) {
                return false;
            }
        }

        return true;
    }

    private static function hash(?string $value): ?string
    {
        return $value === null ? null : hash('sha256', $value);
    }
}
