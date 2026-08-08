<?php

declare(strict_types=1);

namespace App\Forest\Factory;

use App\Forest\Entity\ForestLossYear;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<ForestLossYear>
 */
final class ForestLossYearFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return ForestLossYear::class;
    }

    protected function defaults(): array
    {
        // A small loss patch inside the NCA square used by AreaOfInterestFactory.
        $geom = [
            'type' => 'MultiPolygon',
            'coordinates' => [[[[35.3, -3.2], [35.4, -3.2], [35.4, -3.1], [35.3, -3.1], [35.3, -3.2]]]],
        ];

        return [
            'year' => self::faker()->numberBetween(2001, 2023),
            'geom' => json_encode($geom, \JSON_THROW_ON_ERROR),
            'areaHa' => self::faker()->randomFloat(1, 5, 500),
            'source' => 'test',
        ];
    }
}
