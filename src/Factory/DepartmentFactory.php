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

namespace Uhifadhi\Factory;

use Uhifadhi\Entity\Department;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Department>
 */
final class DepartmentFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Department::class;
    }

    protected function defaults(): array
    {
        return [
            'name' => self::faker()->unique()->words(2, true),
            'modules' => [],
        ];
    }
}
