<?php

declare(strict_types=1);

namespace JOetjen\CooperSymfony\Tests\Config;

use JOetjen\CooperSymfony\Tests\Support\IntegrationTestCase;
use JOetjen\CooperSymfony\Tests\Support\TestKernel;
use Symfony\Component\Config\ConfigCache;

/**
 * In debug mode the container is rebuilt when what it was built from
 * changes: every CASC file a load read, every glob import's expansion,
 * and every environment variable read at build.
 */
final class ResourceTrackingTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->project->write('config/config.casc', "import \"packages/*.casc\"\n\${?COOPER_SF_GUARD} probe.mode = \"fast\"\n");
        $this->project->write('config/packages/probe.casc', "probe.name = \"one\"\n");
        $this->setEnv('COOPER_SF_GUARD', null);
    }

    public function testAnUnchangedProjectKeepsItsContainer(): void
    {
        self::assertTrue(self::fresh($this->boot('dev', true)));
    }

    public function testChangingALoadedFileRebuildsTheContainer(): void
    {
        $kernel = $this->boot('dev', true);

        touch($this->project->dir . '/config/packages/probe.casc', time() + 10);

        self::assertFalse(self::fresh($kernel));
    }

    public function testAddingAFileWhereAGlobImportLooksRebuildsTheContainer(): void
    {
        $kernel = $this->boot('dev', true);

        $this->project->write('config/packages/other.casc', "probe.port = 1\n");

        self::assertFalse(self::fresh($kernel));
    }

    public function testDeletingAnImportedFileRebuildsTheContainer(): void
    {
        $kernel = $this->boot('dev', true);

        unlink($this->project->dir . '/config/packages/probe.casc');

        self::assertFalse(self::fresh($kernel));
    }

    public function testCreatingTheMissingDocumentRebuildsTheContainer(): void
    {
        unlink($this->project->dir . '/config/config.casc');
        $kernel = $this->boot('dev', true);

        $this->project->write('config/config.casc', "probe.name = \"new\"\n");

        self::assertFalse(self::fresh($kernel));
    }

    public function testChangingAVariableReadAtBuildRebuildsTheContainer(): void
    {
        $kernel = $this->boot('dev', true);

        $this->setEnv('COOPER_SF_GUARD', '1');

        self::assertFalse(self::fresh($kernel));
    }

    public function testTheRebuiltContainerHasTheChange(): void
    {
        $this->boot('dev', true);
        $this->project->write('config/packages/probe.casc', "probe.name = \"two\"\n");
        touch($this->project->dir . '/config/packages/probe.casc', time() + 10);

        self::assertSame('two', $this->parameter($this->boot('dev', true), 'probe.name'));
    }

    private static function fresh(TestKernel $kernel): bool
    {
        return (new ConfigCache($kernel->containerFile(), true))->isFresh();
    }
}
