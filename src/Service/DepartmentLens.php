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

use Uhifadhi\Entity\Department;
use Uhifadhi\Entity\User;
use Uhifadhi\Trunk\Entity\Module;

/**
 * The department lens, on its own so it stays pure: no database, no state — only the entities
 * handed in. A department re-orders what a member meets, it never gates it, so this returns the
 * same modules every time and only moves the member's own ones to the front.
 */
final readonly class DepartmentLens
{
    /**
     * The same modules back, led by the ones belonging to the viewer's department (derived from
     * their position). Both groups keep their input order, so an ordering the caller already
     * settled survives. A viewer with no department — no user, no position, or a position filed
     * nowhere — gets the input untouched.
     *
     * @param list<Module> $modules
     *
     * @return list<Module>
     */
    public function moduleOrderFor(?User $user, array $modules): array
    {
        $department = $user?->getPosition()?->getDepartment();
        if (!$department instanceof Department) {
            return $modules;
        }

        $mine = [];
        $rest = [];
        foreach ($modules as $module) {
            if ($department->hasModule($module)) {
                $mine[] = $module;
            } else {
                $rest[] = $module;
            }
        }

        return [...$mine, ...$rest];
    }
}
