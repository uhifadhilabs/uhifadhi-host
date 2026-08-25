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

namespace Uhifadhi\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Entity\Department;
use Uhifadhi\Entity\DepartmentGoal;

/**
 * @extends ServiceEntityRepository<DepartmentGoal>
 */
final class DepartmentGoalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DepartmentGoal::class);
    }

    /**
     * One department's declarations, oldest first — the order they were committed to, which is
     * the order a goals rail reads best in.
     *
     * The owning position is fetch-joined: every card names it, and a goals rail is one widget.
     *
     * @return list<DepartmentGoal>
     */
    public function forDepartment(Department $department): array
    {
        /** @var list<DepartmentGoal> $result */
        $result = $this->createQueryBuilder('g')
            ->addSelect('p')
            ->leftJoin('g.owningPosition', 'p')
            ->where('g.department = :department')
            ->setParameter('department', $department)
            ->orderBy('g.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Every goal of every department asked about, keyed by department id.
     *
     * The board draws one row per department and reads each row's goals beside its numbers, so
     * they are gathered in ONE query rather than one per row. A department that declared nothing
     * still gets a key — an empty list is a real answer there, exactly as it is for KPIs.
     *
     * @param list<Department> $departments
     *
     * @return array<int, list<DepartmentGoal>>
     */
    public function forDepartments(array $departments): array
    {
        $byDepartment = [];
        foreach ($departments as $department) {
            $id = $department->getId();
            if (null !== $id) {
                $byDepartment[$id] = [];
            }
        }
        if ([] === $byDepartment) {
            return [];
        }

        /** @var list<DepartmentGoal> $goals */
        $goals = $this->createQueryBuilder('g')
            ->addSelect('p')
            ->leftJoin('g.owningPosition', 'p')
            ->where('g.department IN (:departments)')
            ->setParameter('departments', $departments)
            ->orderBy('g.id', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($goals as $goal) {
            $id = $goal->getDepartment()?->getId();
            if (null !== $id && isset($byDepartment[$id])) {
                $byDepartment[$id][] = $goal;
            }
        }

        return $byDepartment;
    }

    /**
     * A goal by its public address, scoped to the department whose page is asking.
     *
     * Scoped rather than looked up bare so a uuid from another department's page cannot be
     * deleted through this one's form — the URL says which department it is acting on, and the
     * lookup makes that binding real.
     */
    public function findOneOwned(Department $department, Uuid $uuid): ?DepartmentGoal
    {
        return $this->findOneBy(['department' => $department, 'uuid' => $uuid]);
    }
}
