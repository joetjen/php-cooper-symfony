<?php

declare(strict_types=1);

namespace JOetjen\CooperSymfony\Import;

use JOetjen\CooperConfig\Casc\MapValue;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * One bundle's YAML preset, as Symfony reads it: `config/packages/
 * <name>.yaml`, its `when@<env>:` sections, and `config/packages/<env>/
 * <name>.yaml` -- split into the base configuration and one overlay per
 * environment, each a tree php-cooper-config's `Writer` takes.
 *
 * An environment's overlay is its `when@<env>:` section with the
 * environment directory's file merged over it, the way CASC merges one
 * block over another: blocks merge key by key, anything else -- a list
 * included -- replaces. (Symfony may instead append a later file's
 * list for some options; that difference is the document's to
 * express, with `+key`.)
 *
 * @internal
 */
final class YamlPreset
{
    /**
     * @param array<string, mixed> $base the configuration every environment gets, by top-level key
     * @param array<string, array<string, mixed>> $environments environment => its overlay
     * @param list<string> $sources the preset's files, relative to the project
     */
    private function __construct(
        public readonly array $base,
        public readonly array $environments,
        public readonly array $sources,
    ) {
    }

    /**
     * The preset named `$name` under `$projectDir`, or `null` when there
     * is no file of that name.
     *
     * @param string $projectDir the application's root
     * @param string $name the file's name without `.yaml`, usually the bundle's alias
     * @param PresetValues $values converts each value, and collects what it could not
     * @throws \InvalidArgumentException when a file is not valid YAML
     */
    public static function read(string $projectDir, string $name, PresetValues $values): ?self
    {
        $packages = "{$projectDir}/config/packages";
        $base = [];
        $environments = [];
        $sources = [];

        $file = self::find($packages, $name);
        if ($file !== null) {
            $sources[] = self::relative($projectDir, $file);
            foreach (self::parse($file, $values, $projectDir) as $key => $value) {
                $key = (string) $key;
                if (str_starts_with($key, 'when@')) {
                    $env = substr($key, 5);
                    $environments[$env] = self::merge($environments[$env] ?? [], self::blocks($value, $values, "{$key}"));
                } elseif ($key === 'imports' || $key === 'services') {
                    $values->note(self::relative($projectDir, $file) . ": \"{$key}\" is Symfony's wiring, not configuration -- move it to config/services.yaml by hand");
                } else {
                    $converted = $values->convert($value, $key);
                    if ($converted !== PresetValues::SKIP) {
                        $base[$key] = $converted ?? new MapValue();
                    }
                }
            }
        }

        foreach (glob("{$packages}/*", GLOB_ONLYDIR) ?: [] as $dir) {
            $envFile = self::find($dir, $name);
            if ($envFile === null) {
                continue;
            }
            $env = basename($dir);
            $sources[] = self::relative($projectDir, $envFile);
            $environments[$env] = self::merge($environments[$env] ?? [], self::blocks(self::parse($envFile, $values, $projectDir), $values, ''));
        }

        return $sources === [] ? null : new self($base, $environments, $sources);
    }

    /**
     * Every preset name under `$projectDir`: each `config/packages/*.yaml`,
     * and each file only an environment's directory has.
     *
     * @return list<string> sorted
     */
    public static function names(string $projectDir): array
    {
        $packages = "{$projectDir}/config/packages";
        $names = [];
        // Four globs rather than one with braces: GLOB_BRACE is missing
        // from some C libraries (Alpine's musl among them).
        $files = [];
        foreach (['*.yaml', '*.yml', '*/*.yaml', '*/*.yml'] as $pattern) {
            $files = [...$files, ...(glob("{$packages}/{$pattern}") ?: [])];
        }
        foreach ($files as $file) {
            $names[pathinfo($file, PATHINFO_FILENAME)] = true;
        }
        $names = array_map('strval', array_keys($names));
        sort($names);

        return $names;
    }

    private static function find(string $dir, string $name): ?string
    {
        foreach (['yaml', 'yml'] as $extension) {
            if (is_file("{$dir}/{$name}.{$extension}")) {
                return "{$dir}/{$name}.{$extension}";
            }
        }

        return null;
    }

    /**
     * A file's top-level entries. `!php/const` is read as the constant's
     * value -- CASC has no form for a PHP constant -- and noted.
     *
     * @return array<array-key, mixed>
     * @throws \InvalidArgumentException
     */
    private static function parse(string $file, PresetValues $values, string $projectDir): array
    {
        $source = (string) file_get_contents($file);
        if (preg_match_all('/!php\/const\s+([^\s,\]}]+)/', $source, $m) > 0) {
            foreach ($m[1] as $constant) {
                $values->note(self::relative($projectDir, $file) . ": !php/const {$constant} has no CASC form; written as its value, " . var_export(defined($constant) ? constant($constant) : null, true));
            }
        }
        try {
            $parsed = Yaml::parse($source, Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_CUSTOM_TAGS | Yaml::PARSE_CONSTANT);
        } catch (ParseException $e) {
            throw new \InvalidArgumentException(self::relative($projectDir, $file) . ': ' . $e->getMessage(), 0, $e);
        }

        return $parsed instanceof \stdClass ? get_object_vars($parsed) : [];
    }

    /**
     * A `when@<env>:` section, or an environment file: bundle blocks by
     * name.
     *
     * @return array<string, mixed>
     */
    private static function blocks(mixed $section, PresetValues $values, string $where): array
    {
        $out = [];
        $entries = $section instanceof \stdClass ? get_object_vars($section) : (is_array($section) ? $section : []);
        foreach ($entries as $key => $value) {
            $key = (string) $key;
            $converted = $values->convert($value, $where === '' ? $key : "{$where}.{$key}");
            if ($converted !== PresetValues::SKIP) {
                $out[$key] = $converted ?? new MapValue();
            }
        }

        return $out;
    }

    /**
     * `$over` merged over `$under` as CASC merges a block over a block.
     *
     * @param array<array-key, mixed> $under
     * @param array<array-key, mixed> $over
     * @return array<array-key, mixed>
     */
    private static function merge(array $under, array $over): array
    {
        foreach ($over as $key => $value) {
            $existing = $under[$key] ?? null;
            if (self::isBlock($existing) && self::isBlock($value)) {
                $merged = self::merge(self::entries($existing), self::entries($value));
                $under[$key] = $merged === [] || array_is_list($merged) ? new MapValue($merged) : $merged;
            } else {
                $under[$key] = $value;
            }
        }

        return $under;
    }

    private static function isBlock(mixed $value): bool
    {
        return $value instanceof MapValue || (is_array($value) && $value !== [] && !array_is_list($value));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function entries(mixed $block): array
    {
        if ($block instanceof MapValue) {
            return $block->entries;
        }

        return is_array($block) ? $block : [];
    }

    private static function relative(string $projectDir, string $file): string
    {
        return str_starts_with($file, $projectDir . '/') ? substr($file, strlen($projectDir) + 1) : $file;
    }
}
