<?php

declare(strict_types=1);

namespace JOetjen\CooperSymfony\Tests\Kernel;

use JOetjen\CooperSymfony\Tests\Support\IntegrationTestCase;
use JOetjen\CooperSymfony\Tests\Support\OwnConfigurationKernel;

/**
 * What the kernel trait leaves to Symfony, and what it lets a kernel do
 * for itself.
 */
final class CooperKernelTraitTest extends IntegrationTestCase
{
    public function testServicesYamlIsStillImported(): void
    {
        $this->project->write('config/services.yaml', "parameters:\n    from_services: true\nservices:\n    probe.clock:\n        class: ArrayObject\n        public: true\n");
        $this->project->write('config/services_test.yaml', "parameters:\n    from_services_test: true\n");

        $kernel = $this->boot();

        self::assertTrue($this->parameter($kernel, 'from_services'));
        self::assertTrue($this->parameter($kernel, 'from_services_test'));
        self::assertTrue($kernel->getContainer()->has('probe.clock'));
    }

    public function testConfigPackagesIsNotRead(): void
    {
        $this->project->write('config/packages/probe.yaml', "probe:\n    name: from-yaml\n");

        self::assertSame('probe', $this->parameter($this->boot(), 'probe.name'));
    }

    public function testAKernelWithItsOwnConfigureContainerCanImportCascItself(): void
    {
        $this->project->write('config/config.casc', "probe.name = \"main\"\n");
        $this->project->write('config/extra.casc', "probe.port = 81\n");

        $kernel = new OwnConfigurationKernel('test', false, $this->project->dir);
        $kernel->boot();

        try {
            self::assertSame('main', $kernel->getContainer()->getParameter('probe.name'));
            self::assertSame(81, $kernel->getContainer()->getParameter('probe.port'));
        } finally {
            $kernel->shutdown();
        }
    }
}
