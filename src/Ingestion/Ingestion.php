<?php

declare(strict_types=1);

namespace Uhifadhi\Ingestion;

/**
 * Marker for the Ingestion bounded context — how external data enters the platform.
 *
 * Ingestion reads areas of interest from the Spatial kernel and fills topic tables
 * (Forest now; future topics as they arrive); topics never depend back on it. Each
 * dataset gets an adapter (Hansen GFC first) built on the GDAL bundle's compiled
 * tools + PostGIS SQL, and every run writes a DatasetRun provenance record —
 * what ran, with which parameters, and what it produced.
 */
final class Ingestion
{
}
