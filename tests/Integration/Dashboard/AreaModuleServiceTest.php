<?php

declare(strict_types=1);

namespace Uhifadhi\Tests\Integration\Dashboard;

use Uhifadhi\Composition\Enum\ModuleStatus;
use Uhifadhi\Composition\Factory\AreaModuleFactory;
use Uhifadhi\Composition\Factory\ModuleFactory;
use Uhifadhi\Composition\Repository\AreaModuleRepository;
use Uhifadhi\Dashboard\Service\AreaModuleService;
use Uhifadhi\Spatial\Entity\AreaOfInterest;
use Uhifadhi\Spatial\Factory\AreaOfInterestFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The sub-nav follows an area's composition when it has one, and falls back to a default module set
 * when it doesn't — so a freshly-imported (uncomposed) area still shows a sensible sub-nav, while a
 * composed area's toggles and ordering drive what appears.
 */
final class AreaModuleServiceTest extends KernelTestCase
{
    use Factories;

    private function service(): AreaModuleService
    {
        $repo = self::getContainer()->get(AreaModuleRepository::class);
        \assert($repo instanceof AreaModuleRepository);

        return new AreaModuleService($repo);
    }

    public function testAnUncomposedAreaFallsBackToTheDefaultModules(): void
    {
        self::bootKernel();
        $modules = $this->service()->modules(AreaOfInterestFactory::createOne());

        self::assertSame('overview', $modules[0]['slug']);
        self::assertSame('forest', $modules[1]['slug']);
        self::assertSame('live', $modules[1]['status']);
        self::assertCount(11, $modules);
        foreach ($modules as $m) {
            self::assertNotSame('', $m['label']);
            self::assertNotSame('', $m['blurb']);
        }
    }

    public function testAComposedAreaFollowsItsActiveModulesInOrder(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        $this->compose($area, 'overview', 'Overview', ModuleStatus::Hub, 0);
        $this->compose($area, 'forest', 'Forest loss', ModuleStatus::Live, 1);
        $this->compose($area, 'climate', 'Climate', ModuleStatus::Template, 2);

        $modules = $this->service()->modules($area);

        self::assertSame(['overview', 'forest', 'climate'], array_column($modules, 'slug'));
        self::assertSame('live', $modules[1]['status']);
    }

    public function testPageResolvesAComposedModuleButNotTheHubOrOneNotOnTheArea(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        $this->compose($area, 'overview', 'Overview', ModuleStatus::Hub, 0);
        $this->compose($area, 'forest', 'Forest loss', ModuleStatus::Live, 1);
        $service = $this->service();

        $forestPage = $service->page($area, 'forest');
        self::assertNotNull($forestPage);
        self::assertSame('Forest loss', $forestPage['label']);
        self::assertNull($service->page($area, 'overview'), 'the hub is the show page, not a module page');
        self::assertNull($service->page($area, 'climate'), 'a module not on the area 404s');
        self::assertSame([], $service->planned());
    }

    private function compose(AreaOfInterest $area, string $slug, string $name, ModuleStatus $status, int $position): void
    {
        AreaModuleFactory::createOne([
            'area' => $area, 'active' => true, 'position' => $position,
            'module' => ModuleFactory::new(['slug' => $slug, 'name' => $name, 'status' => $status]),
        ]);
    }
}
