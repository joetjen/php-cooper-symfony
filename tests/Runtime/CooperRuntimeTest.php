<?php

declare(strict_types=1);

namespace JOetjen\CooperSymfony\Tests\Runtime;

use JOetjen\CooperSymfony\Runtime\CooperRuntime;
use JOetjen\CooperSymfony\Tests\Support\IntegrationTestCase;

/**
 * The runtime class an application names in `extra.runtime.class`:
 * Cooper's `.env` files, exported before the kernel exists, in place of
 * Symfony's own.
 */
final class CooperRuntimeTest extends IntegrationTestCase
{
    private int $umask;

    protected function setUp(): void
    {
        parent::setUp();
        $this->umask = umask();
        foreach (['APP_ENV', 'APP_DEBUG', 'COOPER_ENV', 'COOPER_SF_A', 'COOPER_SF_B'] as $name) {
            $this->setEnv($name, null);
        }
        $this->remember('APP_PROJECT_DIR');
    }

    protected function tearDown(): void
    {
        // A debug runtime opens the umask for the cache directory, as
        // Symfony's does; it is the test process's, so it goes back.
        umask($this->umask);
        parent::tearDown();
    }

    public function testDotenvFilesAreExportedBeforeTheKernelBoots(): void
    {
        $this->project->write('.env', "COOPER_SF_A=from-dotenv\n");

        $this->runtime();

        self::assertSame('from-dotenv', getenv('COOPER_SF_A'));
        self::assertSame('from-dotenv', $_ENV['COOPER_SF_A']);
        self::assertSame('from-dotenv', $_SERVER['COOPER_SF_A']);
    }

    public function testTheRealEnvironmentOutranksTheFiles(): void
    {
        $this->project->write('.env', "COOPER_SF_A=from-dotenv\n");
        $this->setEnv('COOPER_SF_A', 'real');

        $this->runtime();

        self::assertSame('real', getenv('COOPER_SF_A'));
    }

    public function testAnUnsetAppEnvIsTakenFromCooperEnv(): void
    {
        $this->project->write('.env', "COOPER_ENV=prod\n");
        $this->project->write('.env.prod', "COOPER_SF_B=prod-file\n");

        $this->runtime();

        self::assertSame('prod', $_SERVER['APP_ENV']);
        self::assertSame('prod', getenv('APP_ENV'));
        self::assertSame('prod-file', getenv('COOPER_SF_B'));
    }

    public function testWithNeitherSetTheEnvironmentIsDev(): void
    {
        $this->runtime();

        self::assertSame('dev', $_SERVER['APP_ENV']);
        self::assertSame('dev', getenv('COOPER_ENV'));
    }

    public function testASetAppEnvIsLeftAloneAndNamesCoopersEnvironment(): void
    {
        $this->setEnv('APP_ENV', 'test');
        $this->project->write('.env.test', "COOPER_SF_B=test-file\n");

        $this->runtime();

        self::assertSame('test', $_SERVER['APP_ENV']);
        self::assertSame('test', getenv('COOPER_ENV'));
        self::assertSame('test-file', getenv('COOPER_SF_B'));
    }

    public function testAnEnvironmentGivenToTheRuntimeNamesCoopersEnvironmentToo(): void
    {
        $this->project->write('.env', "COOPER_ENV=dev\n");
        $this->project->write('.env.prod', "COOPER_SF_B=prod-file\n");

        $this->runtime(['env' => 'prod']);

        self::assertSame('prod', $_SERVER['APP_ENV']);
        self::assertSame('prod', getenv('COOPER_ENV'));
        self::assertSame('prod-file', getenv('COOPER_SF_B'));
    }

    public function testAppDebugComesFromTheFilesLikeAnyOtherVariable(): void
    {
        $this->project->write('.env', "APP_DEBUG=0\n");

        $this->runtime();

        self::assertSame('0', $_SERVER['APP_DEBUG']);
    }

    public function testAppDebugDefaultsToOnOutsideProd(): void
    {
        $this->runtime();

        self::assertSame('1', $_SERVER['APP_DEBUG']);
    }

    public function testSymfonysOwnDotenvLoadingIsOff(): void
    {
        $runtime = $this->runtime(['disable_dotenv' => false]);

        $options = (new \ReflectionProperty($runtime, 'options'))->getValue($runtime);
        self::assertIsArray($options);
        self::assertTrue($options['disable_dotenv']);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runtime(array $options = []): CooperRuntime
    {
        return new CooperRuntime($options + ['project_dir' => $this->project->dir, 'error_handler' => false]);
    }
}
