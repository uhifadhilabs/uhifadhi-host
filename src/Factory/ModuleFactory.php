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

use UhifadhiLabs\Trunk\Entity\Module;
use UhifadhiLabs\Trunk\Enum\ModuleCategory;
use UhifadhiLabs\Trunk\Enum\ModuleStatus;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Module>
 */
final class ModuleFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Module::class;
    }

    protected function defaults(): array
    {
        return [
            'slug' => self::faker()->unique()->slug(1),
            'name' => self::faker()->unique()->words(2, true),
            'category' => ModuleCategory::Flux,
            'status' => ModuleStatus::Template,
            'dataSource' => self::faker()->words(2, true),
            'pinned' => false,
            'position' => self::faker()->numberBetween(1, 99),
        ];
    }
}
