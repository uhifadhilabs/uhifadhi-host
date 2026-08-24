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

use Uhifadhi\Exception\ZoneImportException;
use Uhifadhi\Model\ZoneFeature;

/**
 * The pure half of the zone import: a GeoJSON FeatureCollection in, named MultiPolygons
 * out — one zone per feature, geometry normalised by {@see GeoJsonNormalizerService}.
 * No database and no persistence, so every shape problem (a nameless feature, a
 * duplicate name, a line where a polygon belongs) is caught before anything is written.
 */
final class ZoneFeatureParser
{
    public function __construct(
        private readonly GeoJsonNormalizerService $normalizer,
    ) {
    }

    /**
     * @param array<array-key, mixed> $document a decoded GeoJSON FeatureCollection
     *
     * @return list<ZoneFeature>
     *
     * @throws ZoneImportException
     */
    public function parse(array $document): array
    {
        if ('FeatureCollection' !== ($document['type'] ?? null)) {
            throw new ZoneImportException('The file must be a GeoJSON FeatureCollection — one feature per zone.');
        }
        $features = $document['features'] ?? null;
        if (!\is_array($features) || [] === $features) {
            throw new ZoneImportException('The FeatureCollection contains no features — there is nothing to import.');
        }

        $zones = [];
        $seen = [];
        $position = 0;
        foreach ($features as $feature) {
            ++$position;
            if (!\is_array($feature)) {
                throw new ZoneImportException(\sprintf('Feature #%d is not a GeoJSON object.', $position));
            }
            $name = $this->nameOf($feature, $position);
            if (isset($seen[$name])) {
                throw new ZoneImportException(\sprintf('Feature #%d repeats the zone name "%s" — zone names must be unique within the area.', $position, $name));
            }
            $seen[$name] = true;
            $zones[] = new ZoneFeature($name, $this->geometryOf($feature, $name));
        }

        return $zones;
    }

    /**
     * The single-zone path — the future draw-on-map flow hands a name and one geometry.
     *
     * @param array<array-key, mixed> $geometry any polygonal GeoJSON (Geometry or Feature)
     *
     * @throws ZoneImportException
     */
    public function parseOne(string $name, array $geometry): ZoneFeature
    {
        $name = trim($name);
        if ('' === $name) {
            throw new ZoneImportException('A zone needs a name.');
        }

        return new ZoneFeature($name, $this->geometryOf(['geometry' => $geometry], $name));
    }

    /**
     * @param array<array-key, mixed> $feature
     */
    private function nameOf(array $feature, int $position): string
    {
        $properties = $feature['properties'] ?? null;
        $name = \is_array($properties) ? ($properties['name'] ?? null) : null;
        if (!\is_string($name) || '' === trim($name)) {
            throw new ZoneImportException(\sprintf('Feature #%d has no "name" property — every zone must be named.', $position));
        }

        return trim($name);
    }

    /**
     * @param array<array-key, mixed> $feature
     */
    private function geometryOf(array $feature, string $name): string
    {
        $geometry = $feature['geometry'] ?? null;
        if (!\is_array($geometry)) {
            throw new ZoneImportException(\sprintf('Zone "%s" has no geometry.', $name));
        }

        try {
            $polygons = $this->normalizer->toMultiPolygonCoordinates($geometry);
        } catch (\InvalidArgumentException $e) {
            throw new ZoneImportException(\sprintf('Zone "%s" is not a polygon: %s', $name, $e->getMessage()), previous: $e);
        }
        if ([] === $polygons) {
            throw new ZoneImportException(\sprintf('Zone "%s" has an empty geometry.', $name));
        }

        return json_encode(['type' => 'MultiPolygon', 'coordinates' => $polygons], \JSON_THROW_ON_ERROR);
    }
}
