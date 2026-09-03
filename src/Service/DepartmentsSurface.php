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
use Uhifadhi\Entity\Position;
use Uhifadhi\Entity\User;
use Uhifadhi\Repository\DepartmentRepository;
use Uhifadhi\Seam\Entity\Module;
use Uhifadhi\Seam\Repository\ModuleRepository;

/**
 * Everything the departments surface's widgets read, gathered ONCE.
 *
 * Seven widgets render on one page and five of them want the same three joins (which
 * departments claim which modules, which positions sit in a department, who holds those
 * positions). Gathered here they cost four queries for the whole page; gathered per widget they
 * would cost four per widget, and a partial that quietly queries is a partial nobody can move
 * to another surface.
 *
 * Every department asked about gets a key in each map — an empty department is a real answer
 * (it is exactly what the staffing-gap reading of the org chart is for), never a missing key a
 * template has to guard.
 */
final readonly class DepartmentsSurface
{
    public function __construct(
        private DepartmentRepository $departments,
        private ModuleRepository $modules,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * The partial contract, in one array: exactly the variables every
     * templates/departments/_w_<id>.html.twig receives.
     *
     * @return array{
     *     departments: list<Department>,
     *     modules: list<Module>,
     *     departmentsByModule: array<int, list<Department>>,
     *     positionsByDepartment: array<int, list<Position>>,
     *     usersByDepartment: array<int, list<User>>,
     * }
     */
    public function context(): array
    {
        $departments = $this->departments->findAllOrdered();
        $modules = $this->modules->catalogue();

        return [
            'departments' => $departments,
            'modules' => $modules,
            'departmentsByModule' => $this->departments->departmentsByModule($modules),
            'positionsByDepartment' => $this->positionsByDepartment($departments),
            'usersByDepartment' => $this->usersByDepartment($departments),
        ];
    }

    /**
     * The positions filed under each department, by name. Unfiled positions belong to no
     * department and simply do not appear.
     *
     * @param list<Department> $departments
     *
     * @return array<int, list<Position>>
     */
    private function positionsByDepartment(array $departments): array
    {
        $byDepartment = self::keyed($departments);
        if ([] === $byDepartment) {
            return [];
        }

        /** @var list<Position> $positions */
        $positions = $this->em->createQueryBuilder()
            ->select('p')
            ->from(Position::class, 'p')
            ->where('p.department IN (:departments)')
            ->setParameter('departments', $departments)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($positions as $position) {
            $id = $position->getDepartment()?->getId();
            if (null !== $id && isset($byDepartment[$id])) {
                $byDepartment[$id][] = $position;
            }
        }

        return $byDepartment;
    }

    /**
     * Who works in each department. Membership is INDIRECT — a person's department comes only
     * via their position — so this joins through it rather than reading a column that does not
     * exist.
     *
     * @param list<Department> $departments
     *
     * @return array<int, list<User>>
     */
    private function usersByDepartment(array $departments): array
    {
        $byDepartment = self::keyed($departments);
        if ([] === $byDepartment) {
            return [];
        }

        /** @var list<User> $users */
        $users = $this->em->createQueryBuilder()
            ->select('u', 'p')
            ->from(User::class, 'u')
            ->join('u.position', 'p')
            ->where('p.department IN (:departments)')
            ->setParameter('departments', $departments)
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($users as $user) {
            $id = $user->getPosition()?->getDepartment()?->getId();
            if (null !== $id && isset($byDepartment[$id])) {
                $byDepartment[$id][] = $user;
            }
        }

        return $byDepartment;
    }

    /**
     * Every department's id, each opening on an empty list — an empty department is a real
     * answer, never a missing key.
     *
     * @param list<Department> $departments
     *
     * @return array<int, list<never>>
     */
    private static function keyed(array $departments): array
    {
        $keyed = [];
        foreach ($departments as $department) {
            $id = $department->getId();
            if (null !== $id) {
                $keyed[$id] = [];
            }
        }

        return $keyed;
    }
}
