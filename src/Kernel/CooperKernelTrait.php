<?php

declare(strict_types=1);

namespace JOetjen\CooperSymfony\Kernel;

use JOetjen\CooperSymfony\DependencyInjection\CascFileLoader;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Config\FileLocator;

/**
 * A `MicroKernelTrait` kernel's configuration, CASC in place of
 * `config/packages/*.yaml`:
 *
 * ```php
 * final class Kernel extends BaseKernel
 * {
 *     use MicroKernelTrait, CooperKernelTrait {
 *         CooperKernelTrait::configureContainer insteadof MicroKernelTrait;
 *     }
 * }
 * ```
 *
 * `configureContainer()` loads `config/config.casc` -- bundle
 * configuration and parameters, see `CascFileLoader` -- and then the
 * services exactly as `MicroKernelTrait` does: `config/services.yaml`
 * and `config/services_<env>.yaml`, else their `.php` counterparts.
 * Service wiring stays Symfony's. `config/packages/` is not read.
 *
 * The `.casc` loader is also added to the kernel's loader resolver, so a
 * kernel with its own `configureContainer()` can call
 * `importCooperConfiguration()` itself, or import a `.casc` file the way
 * it imports any other.
 *
 * `insteadof` is the one line of glue PHP asks for: both traits define
 * `configureContainer()`, and PHP will not pick one silently.
 */
trait CooperKernelTrait
{
    /**
     * Loads the CASC document, then the services as `MicroKernelTrait`
     * does.
     *
     * @param ContainerConfigurator $container the configurator for the services
     * @param LoaderInterface $loader the kernel's loader
     * @param ContainerBuilder $builder the container being built
     */
    private function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $this->importCooperConfiguration($loader, $builder);

        $configDir = $this->getProjectDir() . '/config';
        // `{services}` rather than `services`: a glob, which finds nothing
        // instead of failing when the file does not exist.
        $globDir = preg_replace('{/config$}', '/{config}', $configDir);
        if (is_file($configDir . '/services.yaml')) {
            $container->import($globDir . '/services.yaml');
            $container->import($globDir . '/{services}_' . $this->getEnvironment() . '.yaml');
        } else {
            $container->import($globDir . '/{services}.php');
            $container->import($globDir . '/{services}_' . $this->getEnvironment() . '.php');
        }
    }

    /**
     * Registers the `.casc` loader with `$loader`'s resolver and loads
     * `cooperDocument()` into `$builder`.
     *
     * @param LoaderInterface $loader the kernel's loader
     * @param ContainerBuilder $builder the container being built
     */
    protected function importCooperConfiguration(LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $casc = new CascFileLoader($builder, new FileLocator($this), $this->getEnvironment(), $this->cooperOptions());
        $resolver = $loader->getResolver();
        if ($resolver instanceof LoaderResolver && !self::hasCascLoader($resolver)) {
            $resolver->addLoader($casc);
        }
        $casc->load($this->cooperDocument());
    }

    /**
     * The CASC document: `config/config.casc` in the project. Override to
     * keep it elsewhere.
     *
     * @return string an absolute path
     */
    protected function cooperDocument(): string
    {
        return $this->getProjectDir() . '/config/config.casc';
    }

    /**
     * Cooper's load options -- `resolvers`, `tags`, `modules`,
     * `importSchemes`, the `.env` options -- for an application that
     * needs any. Override to give some; none by default.
     *
     * @return array<string, mixed>
     */
    protected function cooperOptions(): array
    {
        return [];
    }

    private static function hasCascLoader(LoaderResolver $resolver): bool
    {
        foreach ($resolver->getLoaders() as $loader) {
            if ($loader instanceof CascFileLoader) {
                return true;
            }
        }

        return false;
    }
}
