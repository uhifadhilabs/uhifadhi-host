<?php

declare(strict_types=1);

namespace App\Composition\Factory;

use App\Composition\Entity\Module;
use App\Composition\Enum\ModuleCategory;
use App\Composition\Enum\ModuleStatus;
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
