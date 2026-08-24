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
use Uhifadhi\Entity\Module;

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

        // Queried from the Module side: a Department root would be de-duplicated by the
        // hydrator, collapsing a department that claims several of the given modules.
        /** @var list<Module> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('m', 'd')
            ->from(Module::class, 'm')
            ->leftJoin('m.departments', 'd')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($rows as $module) {
            $id = $module->getId();
            if (null === $id) {
                continue;
            }
            $claimants = array_values($module->getDepartments()->toArray());
            // Sorted here, not in DQL: a collection already hydrated in this unit of work
            // keeps its own order, and the caller is promised one.
            usort($claimants, static fn (Department $a, Department $b): int => ($a->getName() ?? '') <=> ($b->getName() ?? ''));
            $byModule[$id] = $claimants;
        }

        return $byModule;
    }
}
