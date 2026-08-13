<?php

declare(strict_types=1);

namespace App\Composition\Factory;

use App\Composition\Entity\AreaModule;
use App\Spatial\Factory\AreaOfInterestFactory;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<AreaModule>
 */
final class AreaModuleFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return AreaModule::class;
    }

    protected function defaults(): array
    {
        return [
            'area' => AreaOfInterestFactory::new(),
            'module' => ModuleFactory::new(),
            'active' => true,
            'position' => self::faker()->numberBetween(0, 99),
        ];
    }
}
