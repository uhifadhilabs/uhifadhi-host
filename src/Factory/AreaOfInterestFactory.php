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

use Uhifadhi\Entity\AreaOfInterest;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<AreaOfInterest>
 */
final class AreaOfInterestFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return AreaOfInterest::class;
    }

    protected function defaults(): array
    {
        // A small square near the NCA, as the GeoJSON string the geometry column
        // type expects (PostGIS parses it on insert).
        $geom = [
            'type' => 'MultiPolygon',
            'coordinates' => [[[[35.0, -3.4], [35.8, -3.4], [35.8, -2.9], [35.0, -2.9], [35.0, -3.4]]]],
        ];

        return [
            'name' => self::faker()->unique()->words(3, true),
            'geom' => json_encode($geom, \JSON_THROW_ON_ERROR),
            'source' => 'test',
        ];
    }
}
