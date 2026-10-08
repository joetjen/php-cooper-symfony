<?php

declare(strict_types=1);

namespace JOetjen\CooperSymfony\Command;

use JOetjen\CooperConfig\Casc\Writer;
use JOetjen\CooperSymfony\Import\LineDiff;
use JOetjen\CooperSymfony\Import\PresetValues;
use JOetjen\CooperSymfony\Import\Reference;
use JOetjen\CooperSymfony\Import\YamlPreset;
use Symfony\Component\Config\Definition\NodeInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * `cooper:import <bundle> | --all [--reference] [--force]`: a bundle's
 * YAML preset moved into CASC.
 *
 * `config/packages/<bundle>.yaml` becomes `config/packages/<bundle>.casc`;
 * each `when@<env>:` section, with `config/packages/<env>/<bundle>.yaml`
 * merged over it, becomes `config/<env>/<bundle>.casc` -- the overlay
 * `import "${COOPER_ENV}/*.casc"` reads. Values are converted by
 * `PresetValues` (`%env(int:PORT)%` is `!int(${PORT})`, `%param%` stays),
 * and what has no CASC form is listed. `config/config.casc` is made to
 * `import "packages/*.casc"` once, before the environment's overlay;
 * a missing one is created.
 *
 * `--all` imports every preset in `config/packages/`. `--reference`
 * follows each imported bundle's file with every option of its
 * configuration tree and its default, commented out -- for a bundle
 * with no preset, the reference alone.
 *
 * A file already there is left alone when it would not change, and not
 * overwritten without `--force` when it would: the difference is shown,
 * and the command fails. The YAML is never touched -- deleting it is
 * the application's step, once the CASC says what it should.
 */
final class ImportCommand extends Command
{
    private const MAIN_DOCUMENT = 'config/config.casc';

    private const PACKAGES_IMPORT = 'import "packages/*.casc"';

    /** The environments a created document gets an overlay directory for, as `cooper:init` does. */
    private const ENVIRONMENTS = ['dev', 'test', 'prod'];

    /**
     * @param string $projectDir the application's root
     * @param KernelInterface|null $kernel the application's kernel, whose bundles `--reference` asks for their configuration trees
     */
    public function __construct(private readonly string $projectDir, private readonly ?KernelInterface $kernel = null)
    {
        parent::__construct('cooper:import');
    }

    protected function configure(): void
    {
        $this->setDescription('Convert a bundle\'s YAML preset in config/packages/ into CASC');
        $this->addArgument('bundle', InputArgument::OPTIONAL, 'The preset\'s name: config/packages/<bundle>.yaml, usually the bundle\'s alias');
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Import every preset in config/packages/');
        $this->addOption('reference', null, InputOption::VALUE_NONE, 'Follow it with every option and its default, commented out');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite a CASC file that would change');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $bundle = $input->getArgument('bundle');
        $all = (bool) $input->getOption('all');
        $reference = (bool) $input->getOption('reference');
        if (!is_string($bundle) && !$all) {
            self::say($output, 'name a preset to import, or pass --all to import every one in config/packages/');

            return self::FAILURE;
        }
        $names = $all ? YamlPreset::names($this->projectDir) : [(string) $bundle];
        $values = new PresetValues();

        try {
            $files = [];
            foreach ($names as $name) {
                $files += $this->filesFor($name, $values, $reference, !$all);
            }
        } catch (\InvalidArgumentException $e) {
            self::say($output, $e->getMessage());

            return self::FAILURE;
        }

        foreach ($values->notes() as $note) {
            self::say($output, "note: {$note}");
        }
        $ok = true;
        foreach ($files as $path => $content) {
            $ok = $this->write($path, $content, (bool) $input->getOption('force'), $output) && $ok;
        }
        $this->ensureMainImport($output);

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The CASC files one preset becomes, by path relative to the project.
     *
     * @param bool $named whether the name was given, rather than found by `--all` -- then a missing reference is an error, not a note
     * @return array<string, string>
     * @throws \InvalidArgumentException when there is nothing to import, or no bundle for `--reference`
     */
    private function filesFor(string $name, PresetValues $values, bool $reference, bool $named): array
    {
        $preset = YamlPreset::read($this->projectDir, $name, $values);
        $tree = $reference ? $this->configurationTree($name, $named, $values) : null;
        if ($preset === null && $tree === null) {
            throw new \InvalidArgumentException("nothing to import: there is no config/packages/{$name}.yaml"
                . ($reference ? '' : ' (--reference writes a bundle\'s options without one)'));
        }

        $writer = new Writer();
        $from = $preset === null ? '' : 'Imported from ' . implode(' and ', $preset->sources) . ' by bin/console cooper:import.';
        $main = $writer->write($preset->base ?? [], $from === '' ? "Every option of {$name}, by bin/console cooper:import --reference." : $from);
        if ($tree !== null) {
            $main .= "\n# Every option of {$name}, with its default, from its configuration tree --\n# uncomment what this application changes.\n#\n" . Reference::commentedOut($name, $tree);
        }
        $files = ["config/packages/{$name}.casc" => $main];
        foreach ($preset->environments ?? [] as $env => $overlay) {
            $files["config/{$env}/{$name}.casc"] = $writer->write($overlay, "The {$env} environment's {$name} settings, over config/packages/{$name}.casc.\n{$from}");
        }

        return $files;
    }

    /**
     * The configuration tree of the bundle whose extension alias is
     * `$alias`, as `config:dump-reference` finds it.
     *
     * @throws \InvalidArgumentException when `$named` and there is no such bundle
     */
    private function configurationTree(string $alias, bool $named, PresetValues $values): ?NodeInterface
    {
        foreach ($this->kernel?->getBundles() ?? [] as $bundle) {
            $extension = $bundle->getContainerExtension();
            if ($extension === null || $extension->getAlias() !== $alias) {
                continue;
            }
            // An extension may read a kernel parameter to shape its tree
            // (FrameworkBundle reads kernel.debug), so the builder it gets
            // has the running kernel's.
            $configuration = $extension instanceof \Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface
                ? $extension->getConfiguration([], new ContainerBuilder(new ParameterBag($this->kernelParameters())))
                : null;
            if ($configuration === null) {
                break;
            }

            return $configuration->getConfigTreeBuilder()->buildTree();
        }
        if ($named) {
            throw new \InvalidArgumentException("--reference: no registered bundle has the extension alias \"{$alias}\", or it has no configuration tree");
        }
        $values->note("{$alias}: no registered bundle with a configuration tree has this alias; no reference written");

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function kernelParameters(): array
    {
        $container = $this->kernel?->getContainer();
        if (!$container instanceof Container) {
            return [];
        }
        $parameters = [];
        foreach ($container->getParameterBag()->all() as $name => $value) {
            if (str_starts_with((string) $name, 'kernel.')) {
                $parameters[(string) $name] = $value;
            }
        }

        return $parameters;
    }

    /**
     * Writes one file -- unless it would not change, or would and
     * `$force` is off.
     *
     * @return bool false when a file was left alone because it would change
     */
    private function write(string $path, string $content, bool $force, OutputInterface $output): bool
    {
        $file = "{$this->projectDir}/{$path}";
        $existing = is_file($file) ? (string) file_get_contents($file) : null;
        if ($existing === $content) {
            self::say($output, "unchanged {$path}");

            return true;
        }
        if ($existing !== null && !$force) {
            self::say($output, "not overwriting {$path} -- it differs from the import (--force overwrites it):");
            self::say($output, rtrim(LineDiff::between($existing, $content, "{$path} (on disk)", "{$path} (imported)"), "\n"));

            return false;
        }
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $content);
        self::say($output, ($existing === null ? 'created ' : 'overwrote ') . $path);

        return true;
    }

    /**
     * Makes `config/config.casc` import `packages/*.casc` -- once, and
     * before the environment's overlay, so an environment's settings
     * still win. A missing document is created with both imports.
     */
    private function ensureMainImport(OutputInterface $output): void
    {
        $file = "{$this->projectDir}/" . self::MAIN_DOCUMENT;
        if (!is_file($file)) {
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            file_put_contents($file, self::mainDocument());
            self::say($output, 'created ' . self::MAIN_DOCUMENT);
            // A glob import matching no file is an error (CASC.md §5.1), so
            // each environment the document imports needs one.
            foreach (self::ENVIRONMENTS as $env) {
                if ((glob("{$this->projectDir}/config/{$env}/*.casc") ?: []) === []) {
                    $this->write("config/{$env}/app.casc", "#@version = 1.0\n\n# The {$env} environment's settings, over config/config.casc.\n# Every .casc file in this directory is imported.\n", false, $output);
                }
            }

            return;
        }
        $document = (string) file_get_contents($file);
        if (preg_match('~^[ \t]*import[ \t]+(["\'])packages/\*\.casc\1~m', $document) === 1) {
            return;
        }
        $line = self::PACKAGES_IMPORT . "\n";
        if (preg_match('~^[ \t]*import[ \t]+["\'][^"\'\n]*\$\{COOPER_ENV\}~m', $document, $m, PREG_OFFSET_CAPTURE) === 1) {
            $document = substr($document, 0, $m[0][1]) . $line . "\n" . substr($document, $m[0][1]);
        } else {
            $document = rtrim($document, "\n") . "\n\n" . $line;
        }
        file_put_contents($file, $document);
        self::say($output, 'added ' . self::PACKAGES_IMPORT . ' to ' . self::MAIN_DOCUMENT);
    }

    private static function mainDocument(): string
    {
        return <<<'CASC'
            #@version = 1.0

            # The application's configuration: each top-level block is one bundle's
            # (framework { ... }), and parameters { ... } holds container parameters.
            # Services stay in config/services.yaml.

            # Each bundle's settings, one file per bundle.
            import "packages/*.casc"

            # Last, so an environment overrides everything above.
            import "${COOPER_ENV}/*.casc"

            CASC;
    }

    /**
     * Writes one line exactly as given: a path or a quoted value may
     * hold `<...>`, which console markup would read as a style tag.
     */
    private static function say(OutputInterface $output, string $line): void
    {
        $output->writeln($line, OutputInterface::OUTPUT_RAW);
    }
}
