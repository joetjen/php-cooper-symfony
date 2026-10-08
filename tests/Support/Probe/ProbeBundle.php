<?php

declare(strict_types=1);

namespace JOetjen\CooperSymfony\Tests\Support\Probe;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * A bundle with a configuration tree of every type a test needs, which
 * hands each processed option back as a `probe.<option>` parameter --
 * so a test sees what the bundle received, after its own validation.
 */
final class ProbeBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('name')->defaultValue('probe')->info('What the probe is called.')->end()
                ->integerNode('port')->defaultValue(80)->end()
                ->floatNode('ratio')->defaultValue(0.5)->end()
                ->booleanNode('enabled')->defaultFalse()->end()
                ->enumNode('mode')->values(['fast', 'safe'])->defaultValue('safe')->end()
                ->arrayNode('hosts')->scalarPrototype()->end()->end()
                ->arrayNode('cache')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('ttl')->defaultValue(60)->end()
                        ->scalarNode('dsn')->defaultNull()->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        foreach ($config as $option => $value) {
            $builder->setParameter("probe.{$option}", $value);
        }
    }
}
