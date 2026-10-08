<?php

declare(strict_types=1);

namespace JOetjen\CooperSymfony;

use JOetjen\CooperConfig\Command\CheckCommand;
use JOetjen\CooperConfig\Command\InitCommand;
use JOetjen\CooperSymfony\Command\ImportCommand;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Cooper's console commands on `bin/console`, rooted at the
 * application's `%kernel.project_dir%`:
 *
 *  - `cooper:init`, `cooper:check` -- php-cooper-config's, unchanged;
 *  - `cooper:import` -- this package's: a bundle's YAML preset into CASC.
 *
 * php-cooper-config's `cooper:cache:clear` is left off on purpose: the
 * loaded configuration lives in the compiled container, which replaces
 * php-cooper-config's compiled cache here, so that command would clear a
 * cache nothing reads. `cache:clear` is the one that matters.
 *
 * Loading the configuration is not the bundle's: `CooperKernelTrait`
 * does it, before any bundle is asked for anything. The bundle takes no
 * configuration of its own.
 */
final class CooperBundle extends AbstractBundle
{
    /**
     * @param array<string, mixed> $config the bundle's (empty) configuration
     * @param ContainerConfigurator $container where the commands are registered
     * @param ContainerBuilder $builder the container being built
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();
        $services->set('cooper.command.init', InitCommand::class)
            ->args([param('kernel.project_dir')])
            ->tag('console.command', ['command' => 'cooper:init']);
        $services->set('cooper.command.check', CheckCommand::class)
            ->args([param('kernel.project_dir')])
            ->tag('console.command', ['command' => 'cooper:check']);
        $services->set('cooper.command.import', ImportCommand::class)
            ->args([param('kernel.project_dir'), service('kernel')])
            ->tag('console.command', ['command' => 'cooper:import']);
    }
}
