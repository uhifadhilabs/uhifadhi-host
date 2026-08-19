<?php

declare(strict_types=1);

namespace Uhifadhi\Composition\Factory;

use Uhifadhi\Composition\Entity\AreaModule;
use Uhifadhi\Spatial\Factory\AreaOfInterestFactory;
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
