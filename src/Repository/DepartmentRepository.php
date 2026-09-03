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
use Uhifadhi\Entity\Department;
use Uhifadhi\Trunk\Entity\Module;

/**
 * @extends ServiceEntityRepository<Department>
 */
final class DepartmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Department::class);
    }

    /**
     * The whole org-wide list, ordered by name.
     *
     * @return list<Department>
     */
    public function findAllOrdered(): array
    {
        /** @var list<Department> $result */
        $result = $this->createQueryBuilder('d')
            ->orderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * The inverse of the attachment: which departments claim each of the given modules, for the
     * library/matrix screens. One query for the whole set — never one per module. Every module
     * asked about gets a key (an unclaimed one an empty list), in the order asked.
     *
     * @param list<Module> $modules
     *
     * @return array<int, list<Department>> keyed by module id, each list ordered by name
     */
    public function departmentsByModule(array $modules): array
    {
        $byModule = [];
        $ids = [];
        foreach ($modules as $module) {
            $id = $module->getId();
            if (null === $id) {
                continue;
            }
            $byModule[$id] = [];
            $ids[] = $id;
        }

        if ([] === $ids) {
            return $byModule;
        }

        // THE PAIRS, NOT THE OBJECTS. A module belongs to the trunk and carries no
        // departments collection to walk — a department is this application's lens
        // over the catalogue, and the runtime that owns modules has no business
        // knowing the concept exists. So the attachment is read as what it is in
        // the database: rows of the join table, ordered once, in SQL.
        //
        // Reading it as pairs also avoids the trap the previous shape had to work
        // around: hydrating entities across a filtered join leaves each side
        // holding a collection that looks complete and is not.
        /** @var list<array{moduleId: int, departmentId: int}> $pairs */
        $pairs = $this->createQueryBuilder('d')
            ->select('m.id AS moduleId', 'd.id AS departmentId')
            ->join('d.modules', 'm')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('d.name', 'ASC')
            ->getQuery()
            ->getScalarResult();

        if ([] === $pairs) {
            return $byModule;
        }

        $departments = [];
        foreach ($this->findBy(['id' => array_column($pairs, 'departmentId')]) as $department) {
            $departments[(int) $department->getId()] = $department;
        }

        foreach ($pairs as $pair) {
            $department = $departments[$pair['departmentId']] ?? null;
            if (null !== $department) {
                $byModule[$pair['moduleId']][] = $department;
            }
        }

        return $byModule;
    }
}
