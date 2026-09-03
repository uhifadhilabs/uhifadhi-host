<?php

declare(strict_types=1);

/*
 * This file is part of uhifadhi.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Uhifadhi\Factory\AreaModuleFactory;
use Uhifadhi\Factory\AreaOfInterestFactory;
use Uhifadhi\Factory\ModuleFactory;
use Uhifadhi\Model\ParkedModule;
use Uhifadhi\Service\AreaCompositionService;
use Uhifadhi\Trunk\Entity\Module;
use Uhifadhi\Trunk\Enum\ModuleCategory;
use Uhifadhi\Trunk\Repository\AreaModuleRepository;
use Uhifadhi\Trunk\Repository\ModuleRepository;
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

    public function testCatalogueModulesTheAreaHasNoRowForCountAsParked(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne(); // created after the catalogue seed — no assignments at all
        ModuleFactory::createOne(['slug' => 'roads', 'name' => 'Roads', 'category' => ModuleCategory::Pressure]);
        ModuleFactory::createOne(['slug' => 'forest', 'name' => 'Forest loss', 'category' => ModuleCategory::Flux]);

        $parked = $this->service()->parkedFor($area);

        self::assertCount(2, $parked, 'the whole catalogue is available to a row-less area');
        self::assertSame(['forest', 'roads'], $this->slugsOf($parked));
        self::assertNull($parked[0]->assignment, 'no row is invented on read');
    }

    public function testParkedMixesInactiveRowsWithRowLessCatalogueModules(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        $fires = ModuleFactory::createOne(['slug' => 'fires', 'name' => 'Fires', 'category' => ModuleCategory::Pressure]);
        AreaModuleFactory::createOne(['area' => $area, 'module' => $fires, 'active' => false, 'position' => 3]);
        AreaModuleFactory::createOne(['area' => $area, 'active' => true,
            'module' => ModuleFactory::new(['slug' => 'forest', 'category' => ModuleCategory::Flux])]);
        ModuleFactory::createOne(['slug' => 'roads', 'name' => 'Roads', 'category' => ModuleCategory::Pressure]);

        $grouped = $this->service()->parkedByCategory($area);

        self::assertSame(['Pressure'], array_keys($grouped));
        self::assertSame(['fires', 'roads'], $this->slugsOf($grouped['Pressure']));
        self::assertNotNull($grouped['Pressure'][0]->assignment, 'the parked row keeps its assignment');
        self::assertNull($grouped['Pressure'][1]->assignment, 'the never-assigned module has none');
    }

    /**
     * @param list<ParkedModule> $parked
     *
     * @return list<string>
     */
    private function slugsOf(array $parked): array
    {
        $slugs = array_map(static fn (ParkedModule $p): string => (string) $p->module->getSlug(), $parked);
        sort($slugs);

        return $slugs;
    }
}
