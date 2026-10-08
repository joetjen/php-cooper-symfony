<?php

declare(strict_types=1);

namespace JOetjen\CooperSymfony\Tests\Support;

use JOetjen\CooperSymfony\CooperBundle;
use JOetjen\CooperSymfony\Kernel\CooperKernelTrait;
use JOetjen\CooperSymfony\Tests\Support\Probe\ProbeBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

/**
 * A kernel with a `configureContainer()` of its own, which README.md
 * says may call `importCooperConfiguration()` itself -- and, the loader
 * being registered by then, import another `.casc` file as it would
 * any other.
 */
final class OwnConfigurationKernel extends Kernel
{
    use MicroKernelTrait;
    use CooperKernelTrait;

    public function __construct(string $environment, bool $debug, private readonly string $projectDir)
    {
        parent::__construct($environment, $debug);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new ProbeBundle();
        yield new CooperBundle();
    }

    public function getProjectDir(): string
    {
        return $this->projectDir;
    }

    protected function getContainerClass(): string
    {
        return parent::getContainerClass() . 'X' . substr(hash('xxh128', $this->projectDir), 0, 12);
    }

    private function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $this->importCooperConfiguration($loader, $builder);
        $container->import($this->projectDir . '/config/extra.casc');
    }
}
