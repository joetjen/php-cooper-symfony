<?php

declare(strict_types=1);

namespace JOetjen\CooperSymfony\Tests\DependencyInjection;

use JOetjen\Cooper\CooperError;
use JOetjen\CooperSymfony\Tests\Support\IntegrationTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * `config/config.casc` in place of `config/packages/*.yaml`, as an
 * application sees it: booted through `CooperKernelTrait`.
 */
final class CascFileLoaderTest extends IntegrationTestCase
{
    // ---- bundle configuration and parameters ----------------------------------

    public function testEachTopLevelBlockIsThatBundlesConfiguration(): void
    {
        $this->project->write('config/config.casc', "probe {\n  name = \"from casc\"\n  port = 8080\n  hosts = [\"a\", \"b\"]\n  cache.ttl = 5\n}\n");

        $kernel = $this->boot();

        self::assertSame('from casc', $this->parameter($kernel, 'probe.name'));
        self::assertSame(8080, $this->parameter($kernel, 'probe.port'));
        self::assertSame(['a', 'b'], $this->parameter($kernel, 'probe.hosts'));
        self::assertSame(['ttl' => 5, 'dsn' => null], $this->parameter($kernel, 'probe.cache'));
    }

    public function testABundleStillValidatesItsOwnConfiguration(): void
    {
        $this->project->write('config/config.casc', "probe.colour = \"red\"\n");

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Unrecognized option "colour" under "probe"');

        $this->boot();
    }

    public function testAValueOfTheWrongTypeFailsTheBundlesValidation(): void
    {
        $this->project->write('config/config.casc', "probe.mode = \"reckless\"\n");

        $this->expectException(InvalidConfigurationException::class);

        $this->boot();
    }

    public function testATopLevelBlockNamingNoBundleIsAnError(): void
    {
        $this->project->write('config/config.casc', "nobundle.x = 1\n");

        $this->expectExceptionMessage('nobundle');

        $this->boot();
    }

    public function testATopLevelValueThatIsNotABlockIsAnError(): void
    {
        $this->project->write('config/config.casc', "probe = 1\n");

        $this->expectExceptionMessage('"probe" must be a block');

        $this->boot();
    }

    public function testTheParametersBlockBecomesContainerParameters(): void
    {
        $this->project->write('config/config.casc', "parameters {\n  admin_email = \"a@example.com\"\n  app.locales = [\"en\", \"de\"]\n  app.limits.max = 3\n  \"mailer.from\" = \"me\"\n}\n");

        $kernel = $this->boot();

        self::assertSame('a@example.com', $this->parameter($kernel, 'admin_email'));
        self::assertSame(['en', 'de'], $this->parameter($kernel, 'app.locales'));
        self::assertSame(3, $this->parameter($kernel, 'app.limits.max'));
        self::assertSame('me', $this->parameter($kernel, 'mailer.from'));
    }

    public function testASymfonyParameterReferenceIsLeftForSymfonyToResolve(): void
    {
        $this->project->write('config/config.casc', "parameters.app.cache_dir = \"%kernel.project_dir%/var/app\"\nprobe.name = \"%kernel.environment%\"\n");

        $kernel = $this->boot();

        self::assertSame($this->project->dir . '/var/app', $this->parameter($kernel, 'app.cache_dir'));
        self::assertSame('test', $this->parameter($kernel, 'probe.name'));
    }

    public function testCasCMeasurementsArriveAsPlainValues(): void
    {
        $this->project->write('config/config.casc', "parameters {\n  ttl = 2s\n  size = 1KiB\n  level = info\n  ip = 10.0.0.1\n}\n");

        $kernel = $this->boot();

        self::assertSame(2000, $this->parameter($kernel, 'ttl'));
        self::assertSame(1024, $this->parameter($kernel, 'size'));
        self::assertSame('info', $this->parameter($kernel, 'level'));
        self::assertSame('10.0.0.1', $this->parameter($kernel, 'ip'));
    }

    public function testAMissingDocumentConfiguresNothing(): void
    {
        $kernel = $this->boot();

        self::assertSame('probe', $this->parameter($kernel, 'probe.name'));
    }

    public function testADocumentThatDoesNotLoadStopsTheBuildWithCoopersError(): void
    {
        $this->project->write('config/config.casc', "probe.name = \${UNSET_FOR_SURE:?\"set it\"} \n broken = \n");

        $this->expectException(CooperError::class);

        $this->boot();
    }

    // ---- ${NAME} stays a runtime placeholder ----------------------------------

    public function testAnEnvironmentReferenceIsReadAtRuntimeNotBakedIntoTheContainer(): void
    {
        $this->project->write('config/config.casc', "probe.name = \${COOPER_SF_NAME}\nparameters.greeting = \${COOPER_SF_NAME}\n");
        $this->setEnv('COOPER_SF_NAME', 'zq-first-value');
        $kernel = $this->boot();
        self::assertSame('zq-first-value', $this->parameter($kernel, 'probe.name'));

        $this->setEnv('COOPER_SF_NAME', 'zq-second-value');
        $again = $this->boot();

        self::assertSame('zq-second-value', $this->parameter($again, 'probe.name'));
        self::assertSame('zq-second-value', $this->parameter($again, 'greeting'));
        self::assertStringNotContainsString('zq-first-value', $this->compiled($again));
    }

    public function testATagWrittenAroundAReferenceBecomesATypedPlaceholder(): void
    {
        $this->project->write('config/config.casc', "probe {\n  port = !int(\${COOPER_SF_PORT})\n  enabled = !bool(\${COOPER_SF_ON})\n  ratio = !float(\${COOPER_SF_RATIO})\n}\n");
        $this->setEnv('COOPER_SF_PORT', '8443');
        $this->setEnv('COOPER_SF_ON', 'true');
        $this->setEnv('COOPER_SF_RATIO', '0.25');

        $kernel = $this->boot();

        self::assertSame(8443, $this->parameter($kernel, 'probe.port'));
        self::assertTrue($this->parameter($kernel, 'probe.enabled'));
        self::assertSame(0.25, $this->parameter($kernel, 'probe.ratio'));
    }

    public function testADefaultIsUsedAtRuntimeWhenTheVariableIsUnsetOrEmpty(): void
    {
        $this->project->write('config/config.casc', "probe {\n  name = \${COOPER_SF_NAME:\"fallback\"}\n  port = !int(\${COOPER_SF_PORT:8000})\n}\n");
        $this->setEnv('COOPER_SF_NAME', null);
        $this->setEnv('COOPER_SF_PORT', '');
        $kernel = $this->boot();
        self::assertSame('fallback', $this->parameter($kernel, 'probe.name'));
        self::assertSame(8000, $this->parameter($kernel, 'probe.port'));

        $this->setEnv('COOPER_SF_NAME', 'set');
        $this->setEnv('COOPER_SF_PORT', '9000');
        $again = $this->boot();

        self::assertSame('set', $this->parameter($again, 'probe.name'));
        self::assertSame(9000, $this->parameter($again, 'probe.port'));
    }

    public function testAReferenceInsideAStringKeepsThePlaceholderInsideIt(): void
    {
        $this->project->write('config/config.casc', "probe.cache.dsn = \"redis://\${COOPER_SF_HOST}:6379/0\"\n");
        $this->setEnv('COOPER_SF_HOST', 'cache.internal');

        $kernel = $this->boot();

        self::assertSame('redis://cache.internal:6379/0', $this->parameter($kernel, 'probe.cache')['dsn']);
    }

    public function testAListReferenceBecomesACsvPlaceholder(): void
    {
        $this->project->write('config/config.casc', "parameters.hosts = \${COOPER_SF_HOSTS[]}\n");
        $this->setEnv('COOPER_SF_HOSTS', 'a.example,b.example');

        $kernel = $this->boot();

        self::assertSame(['a.example', 'b.example'], $this->parameter($kernel, 'hosts'));
    }

    public function testAPlaceholderWhereABundleTakesNoneIsSymfonysError(): void
    {
        // Symfony refuses a runtime value for an array node: which keys a
        // bundle sees is decided when the container is built.
        $this->project->write('config/config.casc', "probe.hosts = \${COOPER_SF_HOSTS[]}\n");

        $this->expectExceptionMessage('A dynamic value is not compatible with');

        $this->boot();
    }

    public function testBuildResolvesAReferenceWhenTheContainerIsBuilt(): void
    {
        $this->project->write('config/config.casc', "probe {\n  hosts = !build(\${COOPER_SF_HOSTS[]})\n  mode = !build(\${COOPER_SF_MODE})\n}\n");
        $this->setEnv('COOPER_SF_HOSTS', 'a.example, b.example');
        $this->setEnv('COOPER_SF_MODE', 'fast');

        $kernel = $this->boot();

        self::assertSame(['a.example', 'b.example'], $this->parameter($kernel, 'probe.hosts'));
        self::assertSame('fast', $this->parameter($kernel, 'probe.mode'));
    }

    public function testASecretReadFromTheEnvironmentStaysAPlaceholder(): void
    {
        $this->project->write('config/config.casc', "probe {\n  *name = \${COOPER_SF_SECRET}\n  cache { *dsn = \"literal-secret\" }\n}\n");
        $this->setEnv('COOPER_SF_SECRET', 'zq-hunter2');

        $kernel = $this->boot();

        self::assertSame('zq-hunter2', $this->parameter($kernel, 'probe.name'));
        self::assertSame('literal-secret', $this->parameter($kernel, 'probe.cache')['dsn']);
        self::assertStringNotContainsString('zq-hunter2', $this->compiled($kernel));
    }

    public function testAReferenceSymfonyHasNoPlaceholderForIsResolvedAtBuildWithItsPercentSignsEscaped(): void
    {
        $this->project->write('config/config.casc', "probe.name = \${COOPER_SF_NAME | upcase}\n");
        $this->setEnv('COOPER_SF_NAME', '100%done%');

        $kernel = $this->boot();

        self::assertSame('100%DONE%', $this->parameter($kernel, 'probe.name'));
    }

    public function testTrimBecomesSymfonysTrimProcessor(): void
    {
        $this->project->write('config/config.casc', "probe.name = !trim(\${COOPER_SF_NAME})\n");
        $this->setEnv('COOPER_SF_NAME', '  zq-padded  ');
        $kernel = $this->boot();

        $this->setEnv('COOPER_SF_NAME', '  zq-changed ');

        self::assertSame('zq-changed', $this->parameter($this->boot(), 'probe.name'));
        self::assertStringNotContainsString('zq-padded', $this->compiled($kernel));
    }

    public function testAnIndexOrASubstituteIsResolvedAtBuild(): void
    {
        $this->project->write('config/config.casc', "parameters {\n  first = \${COOPER_SF_HOSTS[0]}\n  flag = \${COOPER_SF_HOSTS:+\"set\"}\n}\n");
        $this->setEnv('COOPER_SF_HOSTS', 'a.example, b.example');

        $kernel = $this->boot();

        self::assertSame('a.example', $this->parameter($kernel, 'first'));
        self::assertSame('set', $this->parameter($kernel, 'flag'));
    }

    public function testASecretKeptAsASecretIsRefused(): void
    {
        $this->project->write('config/config.casc', "probe {\n  cooper-secrets = keep\n  *name = \"s\"\n}\n");

        $this->expectExceptionMessage('a secret kept as a secret has no place in a container');

        $this->boot();
    }

    // ---- references that shape the document resolve at build --------------------

    public function testTheEnvironmentOverlayIsTheKernelEnvironments(): void
    {
        $this->project->write('config/config.casc', "probe.name = \"base\"\nimport \"\${COOPER_ENV}/*.casc\"\n");
        $this->project->write('config/dev/probe.casc', "probe.name = \"dev\"\n");
        $this->project->write('config/prod/probe.casc', "probe.name = \"prod\"\n");
        // A glob import matching no file is an error (CASC.md §5.1).
        $this->project->write('config/test/empty.casc', '');
        $this->setEnv('COOPER_ENV', 'dev');

        self::assertSame('prod', $this->parameter($this->boot('prod'), 'probe.name'));
        self::assertSame('dev', $this->parameter($this->boot('dev'), 'probe.name'));
        self::assertSame('base', $this->parameter($this->boot('test'), 'probe.name'));
    }

    public function testAGuardDecidesAtBuild(): void
    {
        $this->project->write('config/config.casc', "\${?COOPER_SF_FAST} probe.mode = \"fast\"\n");
        $this->setEnv('COOPER_SF_FAST', '1');

        self::assertSame('fast', $this->parameter($this->boot(), 'probe.mode'));
    }

    public function testDotenvFilesAtTheProjectRootAreRead(): void
    {
        $this->project->write('.env', "COOPER_SF_FROM_DOTENV=1\n");
        $this->project->write('config/config.casc', "\${?COOPER_SF_FROM_DOTENV} probe.mode = \"fast\"\n");
        $this->setEnv('COOPER_SF_FROM_DOTENV', null);

        self::assertSame('fast', $this->parameter($this->boot(), 'probe.mode'));
    }
}
