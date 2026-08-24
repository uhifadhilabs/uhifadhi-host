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
use Uhifadhi\Entity\Department;
use Uhifadhi\Entity\Module;
use Uhifadhi\Entity\Position;
use Uhifadhi\Entity\User;
use Uhifadhi\Factory\DepartmentFactory;
use Uhifadhi\Factory\ModuleFactory;
use Uhifadhi\Factory\PositionFactory;
use Uhifadhi\Factory\UserFactory;
use Uhifadhi\Repository\DepartmentRepository;
use Uhifadhi\Repository\PositionRepository;
use Uhifadhi\Service\DepartmentService;
use Zenstruck\Foundry\Test\Factories;

/**
 * Department administration. Attaching is idempotent, and deleting a department is a lens
 * change only: the modules it named survive untouched, the positions that sat in it are simply
 * unfiled, and no person is ever removed with it.
 */
final class DepartmentServiceTest extends KernelTestCase
{
    use Factories;

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function service(): DepartmentService
    {
        // Constructed directly: no controller consumes it yet, so the compiled
        // container inlines this (private) service away.
        $departments = $this->em()->getRepository(Department::class);
        $positions = $this->em()->getRepository(Position::class);
        \assert($departments instanceof DepartmentRepository);
        \assert($positions instanceof PositionRepository);

        return new DepartmentService($this->em(), $departments, $positions);
    }

    public function testCreateAndRename(): void
    {
        self::bootKernel();
        $service = $this->service();

        $department = $service->create('Protection');
        self::assertNotNull($department->getId());
        self::assertNotNull($department->getUuid());

        $service->rename($department, 'Protection & Security');
        $this->em()->clear();

        self::assertSame(
            ['Protection & Security'],
            array_map(static fn (Department $d): ?string => $d->getName(), $this->service()->departments()),
        );
    }

    public function testAttachingAModuleTwiceIsIdempotentAndDetachingRemovesIt(): void
    {
        self::bootKernel();
        $service = $this->service();
        $department = DepartmentFactory::createOne(['name' => 'Protection']);
        $patrols = ModuleFactory::createOne(['slug' => 'patrols']);

        $service->attachModule($department, $patrols);
        $service->attachModule($department, $patrols);
        self::assertCount(1, $department->getModules(), 'attaching twice adds one row');

        $service->detachModule($department, $patrols);
        self::assertCount(0, $department->getModules());

        $service->detachModule($department, $patrols);
        self::assertCount(0, $department->getModules(), 'detaching what is not attached is a no-op');
    }

    public function testDeletingADepartmentKeepsItsModulesUnfilesItsPositionsAndTouchesNoUser(): void
    {
        self::bootKernel();
        $service = $this->service();
        $patrols = ModuleFactory::createOne(['slug' => 'patrols']);
        $department = DepartmentFactory::createOne(['name' => 'Protection', 'modules' => [$patrols]]);
        $ranger = PositionFactory::createOne(['name' => 'Ranger', 'department' => $department]);
        $user = UserFactory::createOne(['position' => $ranger]);
        $userId = $user->getId();

        $service->delete($department);
        $this->em()->clear();

        self::assertSame([], $this->service()->departments(), 'the department is gone');

        $module = $this->em()->getRepository(Module::class)->findOneBy(['slug' => 'patrols']);
        self::assertInstanceOf(Module::class, $module, 'the module it named survives');

        $position = $this->em()->getRepository(Position::class)->findOneBy(['name' => 'Ranger']);
        self::assertInstanceOf(Position::class, $position);
        self::assertNull($position->getDepartment(), 'its positions are unfiled, not deleted');

        $holder = $this->em()->getRepository(User::class)->find((int) $userId);
        self::assertInstanceOf(User::class, $holder, 'people are never cascaded away');
        self::assertSame('Ranger', $holder->getPosition()?->getName());
    }

    public function testTheLensReadsAUsersDepartmentThroughTheirPosition(): void
    {
        self::bootKernel();
        $patrols = ModuleFactory::createOne(['slug' => 'patrols']);
        $forest = ModuleFactory::createOne(['slug' => 'forest']);
        $department = DepartmentFactory::createOne(['name' => 'Protection', 'modules' => [$patrols]]);
        $user = UserFactory::createOne([
            'position' => PositionFactory::createOne(['name' => 'Ranger', 'department' => $department]),
        ]);

        self::assertSame(
            ['patrols', 'forest'],
            array_map(
                static fn (Module $m): ?string => $m->getSlug(),
                $this->service()->moduleOrderFor($user, [$forest, $patrols]),
            ),
        );
    }
}
