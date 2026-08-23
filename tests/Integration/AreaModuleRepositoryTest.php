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

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Uhifadhi\Entity\AreaModule;
use Uhifadhi\Factory\AreaModuleFactory;
use Uhifadhi\Factory\AreaOfInterestFactory;
use Uhifadhi\Factory\ModuleFactory;
use Uhifadhi\Repository\AreaModuleRepository;
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
