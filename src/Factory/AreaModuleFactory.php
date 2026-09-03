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

use Uhifadhi\Seam\Entity\AreaModule;
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
