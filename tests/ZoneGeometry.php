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

namespace Uhifadhi\Tests;

/**
 * Axis-aligned test polygons in WGS84, as the GeoJSON strings the geometry column
 * expects. Squares make the DE-9IM cases readable: shared edges are exact shared
 * coordinates, so "touching" is really touching and not a near miss.
 */
trait ZoneGeometry
{
    /**
     * @return string a MultiPolygon GeoJSON string for the given bounding box
     */
    protected static function square(float $minLon, float $minLat, float $maxLon, float $maxLat): string
    {
        return (string) json_encode([
            'type' => 'MultiPolygon',
            'coordinates' => [[[
                [$minLon, $minLat],
                [$maxLon, $minLat],
                [$maxLon, $maxLat],
                [$minLon, $maxLat],
                [$minLon, $minLat],
            ]]],
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed> the same square as a GeoJSON Feature with a name property
     */
    protected static function squareFeature(string $name, float $minLon, float $minLat, float $maxLon, float $maxLat): array
    {
        /** @var array{type: string, coordinates: list<mixed>} $geometry */
        $geometry = json_decode(self::square($minLon, $minLat, $maxLon, $maxLat), true, 512, \JSON_THROW_ON_ERROR);

        return ['type' => 'Feature', 'properties' => ['name' => $name], 'geometry' => $geometry];
    }

    /**
     * @param list<array<string, mixed>> $features
     *
     * @return array<string, mixed>
     */
    protected static function featureCollection(array $features): array
    {
        return ['type' => 'FeatureCollection', 'features' => $features];
    }
}
