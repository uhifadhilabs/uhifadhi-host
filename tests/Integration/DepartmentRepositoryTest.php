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

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Uhifadhi\Entity\Department;
use Uhifadhi\Factory\DepartmentFactory;
use Uhifadhi\Factory\ModuleFactory;
use Uhifadhi\Repository\DepartmentRepository;
use Uhifadhi\Seam\Entity\Module;
use Zenstruck\Foundry\Test\Factories;

/**
 * Departments are org-wide: one flat, name-unique list, each carrying the modules it works in.
 * The inverse listing answers "which departments claim this module?" for every module in one
 * query — the library/matrix screens must never fan out per row.
 */
final class DepartmentRepositoryTest extends KernelTestCase
{
    use Factories;

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function repository(): DepartmentRepository
    {
        // Via the EM: no controller consumes this repository yet, so the compiled
        // container has nothing to keep its service id alive.
        $repo = $this->em()->getRepository(Department::class);
        \assert($repo instanceof DepartmentRepository);

        return $repo;
    }

    public function testADepartmentRoundTripsWithUuidTimestampsAndModules(): void
    {
        self::bootKernel();
        $patrols = ModuleFactory::createOne(['slug' => 'patrols']);
        $department = new Department()->setName('Protection & Security')->addModule($patrols);

        $this->em()->persist($department);
        $this->em()->flush();
        $this->em()->clear();

        $found = $this->repository()->findOneBy(['name' => 'Protection & Security']);
        self::assertInstanceOf(Department::class, $found);
        self::assertNotNull($found->getUuid());
        self::assertNotNull($found->getCreatedAt());
        self::assertNotNull($found->getUpdatedAt());
        self::assertSame('Protection & Security', (string) $found);
        self::assertSame(['patrols'], array_map(
            static fn (Module $module): ?string => $module->getSlug(),
            $found->getModules()->toArray(),
        ));
    }

    public function testTheNameIsUniqueOrgWide(): void
    {
        self::bootKernel();
        DepartmentFactory::createOne(['name' => 'Ecology']);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->em()->persist(new Department()->setName('Ecology'));
        $this->em()->flush();
    }

    public function testFindAllOrderedReturnsEveryDepartmentByName(): void
    {
        self::bootKernel();
        DepartmentFactory::createOne(['name' => 'Tourism']);
        DepartmentFactory::createOne(['name' => 'Ecology']);
        DepartmentFactory::createOne(['name' => 'Protection']);

        self::assertSame(
            ['Ecology', 'Protection', 'Tourism'],
            array_map(static fn (Department $d): ?string => $d->getName(), $this->repository()->findAllOrdered()),
        );
    }

    public function testDepartmentsByModuleKeysEveryGivenModuleEvenTheUnclaimedOnes(): void
    {
        self::bootKernel();
        $patrols = ModuleFactory::createOne(['slug' => 'patrols']);
        $incidents = ModuleFactory::createOne(['slug' => 'incidents']);
        $tourism = ModuleFactory::createOne(['slug' => 'tourism']);

        DepartmentFactory::createOne(['name' => 'Protection', 'modules' => [$patrols, $incidents]]);
        DepartmentFactory::createOne(['name' => 'Ecology', 'modules' => [$incidents]]);

        $byModule = $this->repository()->departmentsByModule([$patrols, $incidents, $tourism]);

        self::assertSame(
            [(int) $patrols->getId(), (int) $incidents->getId(), (int) $tourism->getId()],
            array_keys($byModule),
            'every module asked about gets a key, in the order asked',
        );
        self::assertSame(['Protection'], $this->names($byModule[(int) $patrols->getId()]));
        self::assertSame(['Ecology', 'Protection'], $this->names($byModule[(int) $incidents->getId()]), 'ordered by name');
        self::assertSame([], $byModule[(int) $tourism->getId()], 'a module no department claims lists nothing');
    }

    public function testDepartmentsByModuleWithoutModulesAsksNothing(): void
    {
        self::bootKernel();
        DepartmentFactory::createOne(['name' => 'Protection']);

        self::assertSame([], $this->repository()->departmentsByModule([]));
    }

    /**
     * @param list<Department> $departments
     *
     * @return list<string|null>
     */
    private function names(array $departments): array
    {
        return array_map(static fn (Department $d): ?string => $d->getName(), $departments);
    }
}
