<?php

declare(strict_types=1);

namespace App\Spatial\Service;

/**
 * Normalises any polygonal GeoJSON document — Geometry, Feature or
 * FeatureCollection — into the coordinates of a single MultiPolygon, so callers
 * (the AOI importer above all) can store one geometry per area regardless of how
 * the source file is shaped.
 */
final class GeoJsonNormalizer
{
    /**
     * @param array<array-key, mixed> $document decoded GeoJSON object
     *
     * @return list<mixed> the `coordinates` value for a MultiPolygon
     *
     * @throws \InvalidArgumentException when the document holds no polygonal geometry
     */
    public function toMultiPolygonCoordinates(array $document): array
    {
        $type = $document['type'] ?? null;
        if (!\is_string($type)) {
            throw new \InvalidArgumentException('GeoJSON object is missing a "type".');
        }

        return match ($type) {
            'FeatureCollection' => $this->fromFeatures($document['features'] ?? null),
            'Feature' => $this->toMultiPolygonCoordinates($this->geometryOf($document)),
            'Polygon' => [$this->coordinatesOf($document)],
            'MultiPolygon' => array_values($this->coordinatesOf($document)),
            default => throw new \InvalidArgumentException(\sprintf('Unsupported geometry type "%s" (need Polygon/MultiPolygon).', $type)),
        };
    }

    /**
     * @param mixed $features
     *
     * @return list<mixed>
     */
    private function fromFeatures($features): array
    {
        if (!\is_array($features)) {
            throw new \InvalidArgumentException('FeatureCollection has no "features" array.');
        }
        $polygons = [];
        foreach ($features as $feature) {
            if (\is_array($feature)) {
                array_push($polygons, ...$this->toMultiPolygonCoordinates($this->geometryOf($feature)));
            }
        }

        return $polygons;
    }

    /**
     * @param array<array-key, mixed> $feature
     *
     * @return array<array-key, mixed>
     */
    private function geometryOf(array $feature): array
    {
        $geometry = $feature['geometry'] ?? null;
        if (!\is_array($geometry)) {
            throw new \InvalidArgumentException('Feature has no "geometry".');
        }

        return $geometry;
    }

    /**
     * @param array<array-key, mixed> $geometry
     *
     * @return array<array-key, mixed>
     */
    private function coordinatesOf(array $geometry): array
    {
        $coordinates = $geometry['coordinates'] ?? null;
        if (!\is_array($coordinates)) {
            throw new \InvalidArgumentException('Geometry has no "coordinates".');
        }

        return $coordinates;
    }
}
