<?php

declare(strict_types=1);

namespace JOetjen\CooperSymfony\Tests;

use JOetjen\CooperSymfony\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The bundle puts php-cooper-config's `cooper:init` and `cooper:check`,
 * and its own `cooper:import`, on `bin/console`, rooted at the
 * application.
 */
final class CooperBundleTest extends IntegrationTestCase
{
    public function testEveryCooperCommandIsOnTheConsole(): void
    {
        $application = new Application($this->boot());

        foreach (['cooper:init', 'cooper:check', 'cooper:import'] as $name) {
            self::assertTrue($application->has($name), "{$name} is not registered");
        }
    }

    public function testCooperCacheClearIsNotOnTheConsole(): void
    {
        // The container cache replaces php-cooper-config's compiled cache
        // here, so its clearing command would clear something never read;
        // `cache:clear` is the command that matters.
        $application = new Application($this->boot());

        self::assertFalse($application->has('cooper:cache:clear'));
    }

    public function testInitScaffoldsTheApplicationsConfigDirectory(): void
    {
        $tester = $this->command('cooper:init');

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertTrue($this->project->has('config/config.casc'));
        self::assertTrue($this->project->has('config/prod/app.casc'));
    }

    public function testCheckLoadsTheApplicationsDocument(): void
    {
        $this->project->write('config/config.casc', "probe.name = \"checked\"\n");

        $tester = $this->command('cooper:check');

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString($this->project->dir . '/config/config.casc', $tester->getDisplay());
        self::assertStringContainsString('top-level keys: probe', $tester->getDisplay());
    }

    public function testCheckFailsOnADocumentThatDoesNotLoad(): void
    {
        $this->project->write('config/config.casc', "probe.name = \"checked\"\n");
        $kernel = $this->boot();
        $this->project->write('config/config.casc', "probe.name = = \n");

        $tester = new CommandTester((new Application($kernel))->find('cooper:check'));
        $tester->execute([]);

        self::assertSame(1, $tester->getStatusCode());
    }

    private function command(string $name): CommandTester
    {
        $tester = new CommandTester((new Application($this->boot()))->find($name));
        $tester->execute([]);

        return $tester;
    }
}
