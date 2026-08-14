<?php

declare(strict_types=1);

namespace App\Ingestion\Enum;

/**
 * What shape a {@see \App\Ingestion\Entity\Dataset} holds. Tabular kinds carry their payload inline
 * (columns + rows) and feed the server-side SVG charts; spatial kinds are file-backed (a path on the
 * shared engine volume) and feed the Leaflet map layers.
 */
enum DatasetKind: string
{
    case Series = 'series';   // an ordered x→y series — bar / line / area charts
    case Table = 'table';     // a general table of rows (e.g. per-class fragmentation metrics)
    case Vector = 'vector';   // a file-backed vector layer (GeoJSON) for the map
    case Raster = 'raster';   // a file-backed raster layer (GeoTIFF/COG) for the map

    public function isTabular(): bool
    {
        return self::Series === $this || self::Table === $this;
    }

    public function isSpatial(): bool
    {
        return self::Vector === $this || self::Raster === $this;
    }
}
