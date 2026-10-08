<?php

declare(strict_types=1);

namespace JOetjen\CooperSymfony\Tests\Command;

use JOetjen\CooperSymfony\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `cooper:import`: a bundle's YAML preset moved into CASC.
 */
final class ImportCommandTest extends IntegrationTestCase
{
    private const PRESET = <<<'YAML'
        probe:
            name: '%env(COOPER_SF_NAME)%'
            port: '%env(int:COOPER_SF_PORT)%'
            enabled: '%env(bool:COOPER_SF_ON)%'
            hosts: ['a', 'b']
            cache:
                dsn: 'redis://%env(COOPER_SF_HOST)%:6379'
                ttl: 30

        when@prod:
            probe:
                mode: fast

        YAML;

    public function testAPresetBecomesACascFileAndAnOverlayPerEnvironment(): void
    {
        $this->project->write('config/packages/probe.yaml', self::PRESET);
        $this->project->write('config/packages/dev/probe.yaml', "probe:\n    name: dev-name\n");

        $tester = $this->run_(['bundle' => 'probe']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $main = $this->project->read('config/packages/probe.casc');
        self::assertStringContainsString('name = ${COOPER_SF_NAME}', $main);
        self::assertStringContainsString('port = !int(${COOPER_SF_PORT})', $main);
        self::assertStringContainsString('enabled = !bool(${COOPER_SF_ON})', $main);
        self::assertStringContainsString('dsn = "redis://${COOPER_SF_HOST}:6379"', $main);
        self::assertStringContainsString('mode = "fast"', $this->project->read('config/prod/probe.casc'));
        self::assertStringContainsString('name = "dev-name"', $this->project->read('config/dev/probe.casc'));
    }

    public function testTheImportedConfigurationIsWhatTheYamlConfigured(): void
    {
        $this->project->write('config/packages/probe.yaml', self::PRESET);
        $this->run_(['bundle' => 'probe']);
        unlink($this->project->dir . '/config/packages/probe.yaml');
        foreach (['COOPER_SF_NAME' => 'n', 'COOPER_SF_PORT' => '81', 'COOPER_SF_ON' => 'true', 'COOPER_SF_HOST' => 'h'] as $name => $value) {
            $this->setEnv($name, $value);
        }

        $kernel = $this->boot('prod');

        self::assertSame('n', $this->parameter($kernel, 'probe.name'));
        self::assertSame(81, $this->parameter($kernel, 'probe.port'));
        self::assertTrue($this->parameter($kernel, 'probe.enabled'));
        self::assertSame(['a', 'b'], $this->parameter($kernel, 'probe.hosts'));
        self::assertSame(['dsn' => 'redis://h:6379', 'ttl' => 30], $this->parameter($kernel, 'probe.cache'));
        self::assertSame('fast', $this->parameter($kernel, 'probe.mode'));
    }

    public function testAParameterReferenceIsKeptForSymfonyToResolve(): void
    {
        $this->project->write('config/packages/probe.yaml', "probe:\n    name: '%kernel.project_dir%/var'\n");

        $this->run_(['bundle' => 'probe']);

        self::assertStringContainsString('name = "%kernel.project_dir%/var"', $this->project->read('config/packages/probe.casc'));
    }

    public function testADefaultProcessorBecomesADefaultReadFromItsParameter(): void
    {
        $this->project->write('config/packages/probe.yaml', "probe:\n    name: '%env(default:probe_name:COOPER_SF_NAME)%'\n    port: '%env(int:default::COOPER_SF_PORT)%'\n");

        $this->run_(['bundle' => 'probe']);

        $casc = $this->project->read('config/packages/probe.casc');
        self::assertStringContainsString('name = ${COOPER_SF_NAME:"%probe_name%"}', $casc);
        self::assertStringContainsString('port = !int(${COOPER_SF_PORT:nil})', $casc);
    }

    public function testAProcessorWithNoCascFormIsKeptAsSymfonysOwnPlaceholder(): void
    {
        $this->project->write('config/packages/probe.yaml', "probe:\n    hosts: '%env(json:COOPER_SF_HOSTS)%'\n");

        $tester = $this->run_(['bundle' => 'probe']);

        self::assertStringContainsString('hosts = "%env(json:COOPER_SF_HOSTS)%"', $this->project->read('config/packages/probe.casc'));
        self::assertStringContainsString('probe.hosts', $tester->getDisplay());
        self::assertStringContainsString('json', $tester->getDisplay());
    }

    public function testAYamlTagWithNoCascFormIsReportedAndLeftOut(): void
    {
        $this->project->write('config/packages/probe.yaml', "probe:\n    name: !php/const PHP_OS_FAMILY\n    hosts: !tagged_iterator app.host\n    port: 1\n");

        $tester = $this->run_(['bundle' => 'probe']);

        $casc = $this->project->read('config/packages/probe.casc');
        self::assertStringNotContainsString('hosts', $casc);
        self::assertStringContainsString('port = 1', $casc);
        self::assertStringContainsString('!tagged_iterator', $tester->getDisplay());
        self::assertStringContainsString('!php/const', $tester->getDisplay());
    }

    public function testServicesInAPresetAreLeftForServicesYaml(): void
    {
        $this->project->write('config/packages/probe.yaml', "probe:\n    port: 2\nservices:\n    App\\Foo: ~\n");

        $tester = $this->run_(['bundle' => 'probe']);

        self::assertStringNotContainsString('services', $this->project->read('config/packages/probe.casc'));
        self::assertStringContainsString('config/services.yaml', $tester->getDisplay());
    }

    public function testTheMainDocumentImportsThePackagesOnceAndBeforeTheEnvironment(): void
    {
        $this->project->write('config/config.casc', "probe.port = 1\nimport \"\${COOPER_ENV}/*.casc\"\n");
        $this->project->write('config/test/app.casc', '');
        $this->project->write('config/packages/probe.yaml', "probe:\n    port: 2\n");

        $this->run_(['bundle' => 'probe']);
        $this->run_(['bundle' => 'probe', '--force' => true]);

        $main = $this->project->read('config/config.casc');
        self::assertSame(1, substr_count($main, 'import "packages/*.casc"'));
        self::assertLessThan(strpos($main, 'import "${COOPER_ENV}'), strpos($main, 'import "packages/*.casc"'));
    }

    public function testAMissingMainDocumentIsCreatedImportingThePackages(): void
    {
        $this->project->write('config/packages/probe.yaml', "probe:\n    port: 2\n");

        $this->run_(['bundle' => 'probe']);

        self::assertStringContainsString('import "packages/*.casc"', $this->project->read('config/config.casc'));
        self::assertSame(2, $this->parameter($this->boot(), 'probe.port'));
    }

    public function testAnExistingFileIsNotOverwrittenWithoutForceAndTheDifferenceIsShown(): void
    {
        $this->project->write('config/packages/probe.yaml', "probe:\n    port: 2\n");
        $this->project->write('config/packages/probe.casc', "probe.port = 1\n");

        $tester = $this->run_(['bundle' => 'probe']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('-probe.port = 1', $tester->getDisplay());
        self::assertStringContainsString('+  port = 2', $tester->getDisplay());
        self::assertStringContainsString('--force', $tester->getDisplay());
        self::assertStringContainsString('probe.port = 1', $this->project->read('config/packages/probe.casc'));

        $forced = $this->run_(['bundle' => 'probe', '--force' => true]);

        self::assertSame(0, $forced->getStatusCode());
        self::assertStringContainsString('port = 2', $this->project->read('config/packages/probe.casc'));
    }

    public function testAnUnchangedFileIsLeftAsItIs(): void
    {
        $this->project->write('config/packages/probe.yaml', "probe:\n    port: 2\n");
        $this->run_(['bundle' => 'probe']);

        $again = $this->run_(['bundle' => 'probe']);

        self::assertSame(0, $again->getStatusCode());
        self::assertStringContainsString('unchanged config/packages/probe.casc', $again->getDisplay());
    }

    public function testAllImportsEveryPreset(): void
    {
        $this->project->write('config/packages/probe.yaml', "probe:\n    port: 2\n");
        $this->project->write('config/packages/framework.yaml', "framework:\n    secret: '%env(APP_SECRET)%'\nwhen@test:\n    framework:\n        test: true\n");
        $this->project->write('config/packages/prod/extra.yaml', "probe:\n    mode: fast\n");

        $tester = $this->run_(['--all' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('secret = ${APP_SECRET}', $this->project->read('config/packages/framework.casc'));
        self::assertStringContainsString('test = true', $this->project->read('config/test/framework.casc'));
        self::assertTrue($this->project->has('config/packages/probe.casc'));
        self::assertStringContainsString('mode = "fast"', $this->project->read('config/prod/extra.casc'));
    }

    public function testReferenceListsEveryOptionWithItsDefaultCommentedOut(): void
    {
        $tester = $this->run_(['bundle' => 'probe', '--reference' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $casc = $this->project->read('config/packages/probe.casc');
        self::assertStringContainsString('#   name = "probe"', $casc);
        self::assertStringContainsString('#   # What the probe is called.', $casc);
        self::assertStringContainsString('#   port = 80', $casc);
        self::assertStringContainsString('#   ratio = 0.5', $casc);
        self::assertStringContainsString('#   enabled = false', $casc);
        self::assertStringContainsString('one of: "fast", "safe"', $casc);
        self::assertStringContainsString('#     ttl = 60', $casc);
        // Every option commented out: the file configures nothing.
        self::assertSame('probe', $this->parameter($this->boot(), 'probe.name'));
    }

    public function testReferenceFollowsThePresetsOwnSettings(): void
    {
        $this->project->write('config/packages/probe.yaml', "probe:\n    port: 2\n");

        $this->run_(['bundle' => 'probe', '--reference' => true]);

        $casc = $this->project->read('config/packages/probe.casc');
        self::assertMatchesRegularExpression('/^  port = 2$/m', $casc);
        self::assertStringContainsString('#   port = 80', $casc);
    }

    public function testAReferenceForNoRegisteredBundleIsAnError(): void
    {
        $tester = $this->run_(['bundle' => 'nobundle', '--reference' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('nobundle', $tester->getDisplay());
    }

    public function testANameWithNoPresetIsAnError(): void
    {
        $tester = $this->run_(['bundle' => 'probe']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('config/packages/probe.yaml', $tester->getDisplay());
    }

    public function testNeitherABundleNorAllIsAnError(): void
    {
        $tester = $this->run_([]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('--all', $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $input
     */
    private function run_(array $input): CommandTester
    {
        // A debug kernel: its container notices the files an import wrote.
        $tester = new CommandTester((new Application($this->boot('test', true)))->find('cooper:import'));
        $tester->execute($input);

        return $tester;
    }
}
