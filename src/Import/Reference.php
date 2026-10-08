<?php

declare(strict_types=1);

namespace JOetjen\CooperSymfony\Import;

use JOetjen\CooperConfig\Casc\Commented;
use JOetjen\CooperConfig\Casc\MapValue;
use JOetjen\CooperConfig\Casc\Writer;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\BaseNode;
use Symfony\Component\Config\Definition\EnumNode;
use Symfony\Component\Config\Definition\NodeInterface;
use Symfony\Component\Config\Definition\PrototypedArrayNode;

/**
 * Every option of a bundle's configuration tree, with its default, as
 * CASC -- each line commented out, so the reference configures nothing
 * until a line is uncommented. The CASC counterpart of `bin/console
 * config:dump-reference`.
 *
 * A node's info, the values an enum allows and whether it is required
 * become comments above it. A default CASC cannot write (a closure, an
 * object) is written as `nil` and says so.
 *
 * @internal
 */
final class Reference
{
    private function __construct()
    {
    }

    /**
     * The commented-out reference for the tree whose root is `$root`.
     *
     * @param string $alias the bundle's extension alias, the block's name
     * @param NodeInterface $root the configuration tree's root
     * @return string `# `-prefixed lines, ending in a newline
     */
    public static function commentedOut(string $alias, NodeInterface $root): string
    {
        $document = (new Writer())->write([$alias => self::value($root)]);
        // The version header is the importing document's to write.
        $body = preg_replace('/\A#@version[^\n]*\n\n?/', '', $document) ?? $document;
        $lines = explode("\n", rtrim($body, "\n"));

        return implode('', array_map(static fn (string $line): string => $line === '' ? "#\n" : "# {$line}\n", $lines));
    }

    /**
     * One node's entry: a block of its children, or its default --
     * either with the node's comment above it.
     */
    private static function entry(NodeInterface $node): mixed
    {
        $value = self::value($node);
        $comment = self::comment($node, $value === self::UNWRITABLE);
        if ($value === self::UNWRITABLE) {
            $value = null;
        }

        return $comment === '' ? $value : new Commented($value, $comment);
    }

    /** Stands for a default with no CASC spelling. */
    private const UNWRITABLE = "\0unwritable";

    private static function value(NodeInterface $node): mixed
    {
        if ($node instanceof ArrayNode && !$node instanceof PrototypedArrayNode) {
            $children = [];
            foreach ($node->getChildren() as $name => $child) {
                $children[$name] = self::entry($child);
            }

            return new MapValue($children);
        }
        $default = $node->hasDefaultValue() ? $node->getDefaultValue() : ($node instanceof PrototypedArrayNode ? [] : null);

        return self::writable($default) ? $default : self::UNWRITABLE;
    }

    /**
     * Whether the `Writer` can spell `$value`: scalars, `null`, and
     * arrays of them.
     */
    private static function writable(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::writable($item)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || (is_scalar($value) && !(is_float($value) && is_nan($value)));
    }

    private static function comment(NodeInterface $node, bool $unwritable): string
    {
        $lines = [];
        $info = $node instanceof BaseNode ? $node->getInfo() : null;
        if (is_string($info) && $info !== '') {
            $lines[] = $info;
        }
        if ($node instanceof EnumNode) {
            $lines[] = 'one of: ' . implode(', ', array_map(self::describe(...), $node->getValues()));
        }
        if ($node->isRequired()) {
            $lines[] = 'required';
        }
        if ($unwritable) {
            $lines[] = 'its default has no CASC spelling';
        }

        return implode("\n", $lines);
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            is_string($value) => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $value,
            $value === null => 'nil',
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof \UnitEnum => $value::class . '::' . $value->name,
            is_scalar($value) => (string) $value,
            default => get_debug_type($value),
        };
    }
}
