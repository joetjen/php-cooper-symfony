<?php

declare(strict_types=1);

namespace JOetjen\CooperSymfony\Tests\Support;

/**
 * A scratch application directory: written to by a test, booted as a
 * `TestKernel`, removed afterwards.
 */
final class Project
{
    public readonly string $dir;

    public function __construct()
    {
        // The real path: Symfony reports the project directory resolved
        // (macOS's temporary directory is behind a symlink).
        $dir = sys_get_temp_dir() . '/cooper-symfony-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->dir = (string) realpath($dir);
    }

    /**
     * Writes `$content` to `$path` (relative to the project), making
     * directories on the way. A `.casc` file gets the version header
     * every document must open with, unless it has one.
     */
    public function write(string $path, string $content): string
    {
        $file = "{$this->dir}/{$path}";
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        if (str_ends_with($path, '.casc') && !str_starts_with($content, '#@version')) {
            $content = "#@version = 1.0\n{$content}";
        }
        file_put_contents($file, $content);

        return $file;
    }

    public function read(string $path): string
    {
        return (string) file_get_contents("{$this->dir}/{$path}");
    }

    public function has(string $path): bool
    {
        return file_exists("{$this->dir}/{$path}");
    }

    public function kernel(string $environment = 'test', bool $debug = false): TestKernel
    {
        return new TestKernel($environment, $debug, $this->dir);
    }

    /**
     * A kernel, booted -- the container built, or read back from the
     * cache when one is already there and fresh.
     */
    public function boot(string $environment = 'test', bool $debug = false): TestKernel
    {
        $kernel = $this->kernel($environment, $debug);
        $kernel->boot();

        return $kernel;
    }

    public function remove(): void
    {
        self::removeTree($this->dir);
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree("{$dir}/{$entry}");
            }
        }
        rmdir($dir);
    }
}
