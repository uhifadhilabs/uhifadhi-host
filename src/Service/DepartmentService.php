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

namespace Uhifadhi\Service;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Entity\Department;
use Uhifadhi\Entity\User;
use Uhifadhi\Repository\DepartmentRepository;
use Uhifadhi\Repository\PositionRepository;
use UhifadhiLabs\Trunk\Entity\Module;

/**
 * Department administration and the department lens. Departments are the organizational view of
 * the same catalogue every member already reaches — {@see moduleOrderFor()} re-orders, it never
 * filters, and deleting a department unfiles its positions without touching a single person.
 */
final readonly class DepartmentService
{
    public function __construct(
        private EntityManagerInterface $em,
        private DepartmentRepository $departments,
        private PositionRepository $positions,
        private DepartmentLens $lens = new DepartmentLens(),
    ) {
    }

    /**
     * @return list<Department>
     */
    public function departments(): array
    {
        return $this->departments->findAllOrdered();
    }

    public function create(string $name): Department
    {
        $department = new Department()->setName($name);

        $this->em->persist($department);
        $this->em->flush();

        return $department;
    }

    public function rename(Department $department, string $name): void
    {
        $department->setName($name);
        $this->em->flush();
    }

    /**
     * Drop a department. A lens change only: its modules keep existing (the join rows go), the
     * positions filed under it are unfiled, and no user is ever removed along with it.
     */
    public function delete(Department $department): void
    {
        $department->getModules()->clear();
        foreach ($this->positions->findBy(['department' => $department]) as $position) {
            $position->setDepartment(null);
        }

        $this->em->remove($department);
        $this->em->flush();
    }

    /** Idempotent: a module already in the department stays attached exactly once. */
    public function attachModule(Department $department, Module $module): void
    {
        $department->addModule($module);
        $this->em->flush();
    }

    public function detachModule(Department $department, Module $module): void
    {
        $department->removeModule($module);
        $this->em->flush();
    }

    /**
     * The lens: the same modules back, led by the viewer's department's own. See
     * {@see DepartmentLens::moduleOrderFor()} — kept there so it stays pure.
     *
     * @param list<Module> $modules
     *
     * @return list<Module>
     */
    public function moduleOrderFor(?User $user, array $modules): array
    {
        return $this->lens->moduleOrderFor($user, $modules);
    }
}
