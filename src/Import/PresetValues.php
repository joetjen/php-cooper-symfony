<?php

declare(strict_types=1);

namespace JOetjen\CooperSymfony\Import;

use JOetjen\CooperConfig\Casc\MapValue;
use JOetjen\CooperConfig\Casc\Raw;
use Symfony\Component\Yaml\Tag\TaggedValue;

/**
 * A YAML preset's values as php-cooper-config's CASC `Writer` takes
 * them -- the reverse of what `EnvPlaceholders` does to a document:
 *
 * | YAML | CASC |
 * |---|---|
 * | `'%env(X)%'`, `'%env(string:X)%'` | `${X}` |
 * | `'%env(int:X)%'`, `float:`, `bool:`, `trim:` | `!int(${X})`, `!float(...)`, `!bool(...)`, `!trim(...)` |
 * | `'%env(csv:X)%'` | `${X[]}` |
 * | `'%env(default:param:X)%'` | `${X:"%param%"}` (`default::X`: `${X:nil}`) |
 * | `'redis://%env(HOST)%:6379'` | `"redis://${HOST}:6379"` |
 * | `'%kernel.project_dir%/var'` | the same string, for Symfony to resolve |
 * | `{}` | `{}` (an empty block, not an empty list) |
 *
 * An `%env()%` with any other processor (`json:`, `file:`, `resolve:`,
 * ...) has no CASC form; it is kept as the string it is, which Symfony
 * still resolves at runtime, and reported. A YAML tag (`!tagged_iterator`,
 * `!php/enum`, ...) has none either, and its entry is left out and
 * reported.
 *
 * @internal
 */
final class PresetValues
{
    /** What `convert()` returns for an entry that is left out. */
    public const SKIP = "\0skip";

    /** The processors a CASC tag around `${...}` means the same as. */
    private const TAGS = ['int' => 'int', 'float' => 'float', 'bool' => 'bool', 'trim' => 'trim'];

    /** A written-out `${NAME}`'s name (CASC.md §7.2). */
    private const NAME = '/\A[A-Za-z_][A-Za-z0-9_]*\z/';

    /** @var list<string> what could not be carried over, one line each */
    private array $notes = [];

    /**
     * `$value` as the `Writer` writes it, or `SKIP`.
     *
     * @param mixed $value a value `Yaml::parse()` gave, with `PARSE_OBJECT_FOR_MAP | PARSE_CUSTOM_TAGS | PARSE_CONSTANT`
     * @param string $where its dotted path, for a note
     * @return mixed the value for the `Writer`, or `SKIP`
     */
    public function convert(mixed $value, string $where): mixed
    {
        return match (true) {
            $value instanceof \stdClass => $this->map(get_object_vars($value), $where),
            is_array($value) => $this->list($value, $where),
            $value instanceof TaggedValue => $this->skip($where, "the YAML tag !{$value->getTag()} has no CASC form; left out"),
            is_string($value) => $this->string($value, $where),
            $value === null, is_scalar($value) => $value,
            $value instanceof \UnitEnum => $this->skip($where, 'an enum case (!php/enum ' . $value::class . "::{$value->name}) has no CASC form; left out"),
            default => $this->skip($where, 'a ' . get_debug_type($value) . ' has no CASC form; left out'),
        };
    }

    /**
     * Records a note that is not about one value -- a whole file's.
     */
    public function note(string $note): void
    {
        $this->notes[] = $note;
    }

    /**
     * @return list<string> everything noted so far
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * @param array<array-key, mixed> $entries
     * @return array<array-key, mixed>|MapValue
     */
    private function map(array $entries, string $where): array|MapValue
    {
        $out = [];
        foreach ($entries as $key => $item) {
            $converted = $this->convert($item, $where === '' ? (string) $key : "{$where}.{$key}");
            if ($converted !== self::SKIP) {
                $out[$key] = $converted;
            }
        }

        // A YAML `{}` stays a block: written over a block, CASC's `{}`
        // leaves it as it is, where `[]` would replace it.
        return $out === [] || array_is_list($out) ? new MapValue($out) : $out;
    }

    /**
     * @param array<array-key, mixed> $items
     * @return list<mixed>
     */
    private function list(array $items, string $where): array
    {
        $out = [];
        foreach (array_values($items) as $index => $item) {
            $converted = $this->convert($item, "{$where}[{$index}]");
            if ($converted !== self::SKIP) {
                $out[] = $converted;
            }
        }

        return $out;
    }

    private function skip(string $where, string $why): string
    {
        $this->notes[] = "{$where}: {$why}";

        return self::SKIP;
    }

    /**
     * A string, its `%env()%` placeholders turned into `${...}` where
     * CASC has a form for them.
     */
    private function string(string $text, string $where): string|Raw
    {
        if (!str_contains($text, '%env(')) {
            return $text;
        }
        if (preg_match('/\A%env\(([^()%]+)\)%\z/', $text, $m) === 1) {
            $reference = self::reference($m[1]);
            if ($reference !== null) {
                return new Raw($reference);
            }
            $this->notes[] = "{$where}: {$text} has no CASC form; kept as Symfony's own placeholder";

            return $text;
        }

        return $this->embedded($text, $where);
    }

    /**
     * A string with placeholders among other text: a double-quoted CASC
     * string interpolating each. Only a plain `%env(NAME)%` can be inside
     * a string -- a typed one is not text -- and text that would itself
     * read as CASC interpolation is left as it is.
     */
    private function embedded(string $text, string $where): string|Raw
    {
        $parts = preg_split('/(%env\([^()%]+\)%)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $casc = '';
        foreach ($parts as $part) {
            if (preg_match('/\A%env\(([^()%]+)\)%\z/', $part, $m) === 1) {
                $name = str_starts_with($m[1], 'string:') ? substr($m[1], 7) : $m[1];
                if (preg_match(self::NAME, $name) !== 1) {
                    $this->notes[] = "{$where}: {$part} inside a string has no CASC form; the string is kept as Symfony's own";

                    return $text;
                }
                $casc .= '${' . $name . '}';
                continue;
            }
            if (preg_match('/[@$%!]\{|![A-Za-z]/', $part) === 1) {
                $this->notes[] = "{$where}: the text around its placeholders would read as CASC interpolation; the string is kept as Symfony's own";

                return $text;
            }
            $casc .= strtr($part, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t']);
        }

        return new Raw('"' . $casc . '"');
    }

    /**
     * The CASC for one placeholder's inside (`int:default:p:PORT`), or
     * `null` without one: `[tag:][csv:][default:param:]NAME`, the chain
     * `EnvPlaceholders` writes for a document.
     */
    private static function reference(string $chain): ?string
    {
        $tokens = explode(':', $chain);
        $name = array_pop($tokens);
        if (preg_match(self::NAME, $name) !== 1) {
            return null;
        }
        $tag = null;
        if (($tokens[0] ?? null) === 'string') {
            array_shift($tokens);
        } elseif (isset($tokens[0], self::TAGS[$tokens[0]])) {
            $tag = self::TAGS[array_shift($tokens)];
        }
        $list = false;
        if (($tokens[0] ?? null) === 'csv') {
            $list = true;
            array_shift($tokens);
        }
        $default = null;
        if (($tokens[0] ?? null) === 'default' && count($tokens) === 2) {
            // `default::X` -- an empty parameter name -- is Symfony's null.
            $default = $tokens[1] === '' ? 'nil' : '"%' . $tokens[1] . '%"';
            $tokens = [];
        }
        // A list's default is not what `csv` would read (an empty variable
        // is an empty list), and a tag reads no list: both stay Symfony's.
        if ($tokens !== [] || ($list && ($default !== null || $tag !== null))) {
            return null;
        }

        $reference = '${' . $name . ($list ? '[]' : '') . ($default === null ? '' : ":{$default}") . '}';

        return $tag === null ? $reference : "!{$tag}({$reference})";
    }
}
