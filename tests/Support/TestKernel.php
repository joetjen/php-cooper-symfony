<?php

declare(strict_types=1);

namespace JOetjen\CooperSymfony\Tests\Support;

use JOetjen\CooperSymfony\CooperBundle;
use JOetjen\CooperSymfony\Kernel\CooperKernelTrait;
use JOetjen\CooperSymfony\Tests\Support\Probe\ProbeBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel;

/**
 * The smallest application this bundle serves: FrameworkBundle, the
 * probe bundle the tests configure, and Cooper -- installed exactly as
 * README.md tells an application to.
 *
 * Every scratch project gets its own container class: two compiled
 * containers of one name in one PHP process would collide.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait, CooperKernelTrait {
        CooperKernelTrait::configureContainer insteadof MicroKernelTrait;
    }

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

    /**
     * The compiled container's file, for a test asking whether it is
     * still fresh.
     */
    public function containerFile(): string
    {
        return $this->getCacheDir() . '/' . $this->getContainerClass() . '.php';
    }

    protected function getContainerClass(): string
    {
        return parent::getContainerClass() . 'X' . substr(hash('xxh128', $this->projectDir), 0, 12);
    }
}
