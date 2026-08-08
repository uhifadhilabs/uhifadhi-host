<?php

declare(strict_types=1);

namespace App\Spatial;

/**
 * Marker for the Spatial bounded context — the shared spatial kernel.
 *
 * Every topic domain (Forest, and future Settlement/Drainage/Invasives…) may depend
 * on Spatial, but Spatial depends on nothing but itself. It owns the cross-cutting spatial
 * primitives so no topic re-implements them:
 *   - Doctrine/    context-specific Doctrine glue (geometry column types come from
 *                  the fundistadi/fundi-postgis bundle)
 *   - Entity/      AreaOfInterest (NCA boundary + buffer), RasterAsset (COG + metadata)
 *   - Service/     ExportService (GeoJSON / CSV / GeoPackage), map/tiling helpers
 *   - Twig/        MapLibre base-map component + Stimulus controller
 *
 * This is a structural marker only; behaviour arrives in the build phase.
 */
final class Spatial
{
}
