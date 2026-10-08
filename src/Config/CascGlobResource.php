<?php

declare(strict_types=1);

namespace JOetjen\CooperSymfony\Config;

use JOetjen\Cooper\ImportGlob;
use Symfony\Component\Config\Resource\SelfCheckingResourceInterface;

/**
 * One CASC glob import (`import "packages/*.casc"`) as a container
 * resource: fresh while the pattern matches the files it matched when
 * the container was built.
 *
 * Symfony's own `GlobResource` is not used because CASC's patterns are
 * Cooper's (CASC.md §5.1) -- the expansion that decides freshness must
 * be the one the load used, which `ImportGlob::changed()` is. A file
 * changed in place is the `FileResource` beside this one's concern.
 */
final class CascGlobResource implements SelfCheckingResourceInterface
{
    /**
     * @param ImportGlob $glob the import's expansion when the container was built
     */
    public function __construct(private readonly ImportGlob $glob)
    {
    }

    /**
     * @return string the import, unique per root and pattern
     */
    public function __toString(): string
    {
        // Symfony caches a resource's freshness by this string for the
        // rest of the process, so the matched files are part of it: the
        // same pattern matching other files is another resource.
        return 'casc-glob:' . $this->glob->root . ':' . $this->glob->pattern . ':' . hash('xxh128', implode("\0", $this->glob->files));
    }

    /**
     * @param int $timestamp unused: a glob's answer does not depend on time
     * @return bool whether the pattern still matches exactly the same files
     */
    public function isFresh(int $timestamp): bool
    {
        return !$this->glob->changed();
    }
}
