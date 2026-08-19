<?php

declare(strict_types=1);

namespace Uhifadhi\Tests\Integration\Composition;

use Uhifadhi\Composition\Entity\Module;
use Uhifadhi\Composition\Enum\ModuleCategory;
use Uhifadhi\Composition\Factory\AreaModuleFactory;
use Uhifadhi\Composition\Factory\ModuleFactory;
use Uhifadhi\Composition\Repository\AreaModuleRepository;
use Uhifadhi\Composition\Repository\ModuleRepository;
use Uhifadhi\Composition\Service\AreaCompositionService;
use Uhifadhi\Spatial\Factory\AreaOfInterestFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The customize-modules mutations: switching a module off parks it (but the pinned Overview can
 * never be switched off), adding a parked module re-activates it, and the parked shop is grouped
 * by category.
 */
final class AreaCompositionServiceTest extends KernelTestCase
{
    use Factories;

    private function service(): AreaCompositionService
    {
        // Constructed directly: until a controller consumes it, the compiled container
        // inlines this (private) service away.
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $areaModules = self::getContainer()->get(AreaModuleRepository::class);
        $modules = self::getContainer()->get(ModuleRepository::class);
        \assert($em instanceof EntityManagerInterface);
        \assert($areaModules instanceof AreaModuleRepository);
        \assert($modules instanceof ModuleRepository);

        return new AreaCompositionService($em, $areaModules, $modules);
    }

    public function testSwitchingAModuleOffParksItButNeverThePinnedOverview(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        $overview = AreaModuleFactory::createOne([
            'area' => $area, 'active' => true, 'position' => 0,
            'module' => ModuleFactory::new(['slug' => 'overview', 'pinned' => true]),
        ]);
        $forest = AreaModuleFactory::createOne([
            'area' => $area, 'active' => true, 'position' => 1,
            'module' => ModuleFactory::new(['slug' => 'forest', 'pinned' => false]),
        ]);

        $this->service()->setActive($forest, false);
        $this->service()->setActive($overview, false); // must be refused

        self::assertFalse($forest->isActive(), 'a normal module parks when switched off');
        self::assertTrue($overview->isActive(), 'the pinned Overview can never be switched off');
    }

    public function testAddingAParkedModuleReactivatesItInPlace(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        $module = ModuleFactory::createOne(['slug' => 'roads']);
        $parked = AreaModuleFactory::createOne(['area' => $area, 'module' => $module, 'active' => false, 'position' => 9]);

        $result = $this->service()->addToArea($area, $module);

        self::assertSame($parked->getId(), $result->getId(), 'no duplicate row — the parked assignment is reused');
        self::assertTrue($result->isActive());
    }

    public function testParkedModulesAreGroupedByCategory(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        AreaModuleFactory::createOne(['area' => $area, 'active' => false,
            'module' => ModuleFactory::new(['slug' => 'roads', 'name' => 'Roads', 'category' => ModuleCategory::Pressure])]);
        AreaModuleFactory::createOne(['area' => $area, 'active' => false,
            'module' => ModuleFactory::new(['slug' => 'fires', 'name' => 'Fires', 'category' => ModuleCategory::Pressure])]);
        AreaModuleFactory::createOne(['area' => $area, 'active' => true,
            'module' => ModuleFactory::new(['slug' => 'forest', 'category' => ModuleCategory::Flux])]);

        $grouped = $this->service()->parkedByCategory($area);

        self::assertSame(['Pressure'], array_keys($grouped), 'only categories with parked modules appear');
        self::assertCount(2, $grouped['Pressure']);
    }
}
