<?php

declare(strict_types=1);

namespace App\Ingestion\Service;

/**
 * Maps an AOI bounding box to GDAL-readable raster source URIs for a dataset
 * release. The seam that lets tests substitute local fixture rasters for the
 * real remote granules.
 */
interface TileSourceInterface
{
    /**
     * @return list<string> GDAL-readable URIs (e.g. /vsicurl/https://… or local paths)
     */
    public function sources(float $minX, float $minY, float $maxX, float $maxY, string $version): array;
}
