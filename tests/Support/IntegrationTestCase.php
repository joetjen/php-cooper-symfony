<?php

declare(strict_types=1);

namespace JOetjen\CooperSymfony\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * A test with a scratch project and a clean process environment: every
 * variable a test sets through `setEnv()` is put back as it was, and
 * every kernel it boots is shut down, whatever the test did.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected Project $project;

    /** @var array<string, array{0: string|false, 1: mixed, 2: mixed}> name => [getenv, $_ENV, $_SERVER] before the test */
    private array $savedEnv = [];

    /** @var list<TestKernel> */
    private array $kernels = [];

    protected function setUp(): void
    {
        $this->project = new Project();
    }

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        foreach ($this->savedEnv as $name => [$env, $superEnv, $server]) {
            $env === false ? putenv($name) : putenv("{$name}={$env}");
            if ($superEnv === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $superEnv;
            }
            if ($server === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
        }
        $this->project->remove();
    }

    /**
     * Sets `$name` in the process environment -- `getenv()`, `$_ENV`
     * and `$_SERVER`, the three places Symfony's `%env()%` reads -- or
     * unsets it for `null`, to be restored after the test.
     */
    protected function setEnv(string $name, ?string $value): void
    {
        $this->remember($name);
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            return;
        }
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    /**
     * Records `$name` as it stands, so `tearDown()` restores it -- for
     * a variable the code under test, not the test, will change.
     */
    protected function remember(string $name): void
    {
        if (!array_key_exists($name, $this->savedEnv)) {
            $this->savedEnv[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
        }
    }

    protected function boot(string $environment = 'test', bool $debug = false): TestKernel
    {
        $kernel = $this->project->boot($environment, $debug);
        $this->kernels[] = $kernel;

        return $kernel;
    }

    /**
     * Every PHP file of `$kernel`'s compiled container, joined: what a
     * value baked into the cache would be found in.
     */
    protected function compiled(TestKernel $kernel): string
    {
        $source = '';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($kernel->getCacheDir(), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $source .= (string) file_get_contents($file->getPathname());
            }
        }

        return $source;
    }

    protected function parameter(TestKernel $kernel, string $name): mixed
    {
        return $kernel->getContainer()->getParameter($name);
    }
}
