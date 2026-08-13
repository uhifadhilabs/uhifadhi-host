<?php

declare(strict_types=1);

namespace App\Tests\Integration\Composition;

use App\Composition\Entity\AreaModule;
use App\Composition\Factory\AreaModuleFactory;
use App\Composition\Factory\ModuleFactory;
use App\Composition\Repository\AreaModuleRepository;
use App\Spatial\Factory\AreaOfInterestFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * An area's sub-nav is its AreaModule rows in `position` order: `activeForArea` returns only the
 * switched-on modules (what the sub-nav renders), while `forArea` also carries the parked ones
 * (what the "customize modules" shop lists). One area's assignments never leak into another's.
 */
final class AreaModuleRepositoryTest extends KernelTestCase
{
    use Factories;

    private function repository(): AreaModuleRepository
    {
        $repo = self::getContainer()->get(AreaModuleRepository::class);
        \assert($repo instanceof AreaModuleRepository);

        return $repo;
    }

    public function testActiveModulesAreReturnedInPositionOrderAndParkedOnesExcluded(): void
    {
        self::bootKernel();
        $area = AreaOfInterestFactory::createOne();
        $overview = ModuleFactory::createOne(['slug' => 'overview', 'name' => 'Overview', 'pinned' => true]);
        $forest = ModuleFactory::createOne(['slug' => 'forest', 'name' => 'Forest loss']);
        $roads = ModuleFactory::createOne(['slug' => 'roads', 'name' => 'Roads']);

        // Deliberately persisted out of order to prove the query sorts by position.
        AreaModuleFactory::createOne(['area' => $area, 'module' => $forest, 'active' => true, 'position' => 1]);
        AreaModuleFactory::createOne(['area' => $area, 'module' => $roads, 'active' => false, 'position' => 2]);
        AreaModuleFactory::createOne(['area' => $area, 'module' => $overview, 'active' => true, 'position' => 0]);

        $active = array_map(static fn (AreaModule $am): ?string => $am->getModule()?->getSlug(), $this->repository()->activeForArea($area));
        self::assertSame(['overview', 'forest'], $active, 'active modules, in position order, parked ones dropped');

        $all = array_map(static fn (AreaModule $am): ?string => $am->getModule()?->getSlug(), $this->repository()->forArea($area));
        self::assertSame(['overview', 'forest', 'roads'], $all, 'the shop lists every assignment incl. parked');
    }

    public function testAssignmentsAreScopedToTheirArea(): void
    {
        self::bootKernel();
        $mine = AreaOfInterestFactory::createOne();
        $other = AreaOfInterestFactory::createOne();
        $module = ModuleFactory::createOne(['slug' => 'forest']);

        AreaModuleFactory::createOne(['area' => $mine, 'module' => $module, 'position' => 0]);

        self::assertCount(1, $this->repository()->forArea($mine));
        self::assertCount(0, $this->repository()->forArea($other), "another area's assignments must not leak in");
    }
}
