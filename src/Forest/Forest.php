<?php

declare(strict_types=1);

namespace App\Forest;

/**
 * Marker for the Forest bounded context — DNCA Topic 6 (Deforestation), the PoC domain.
 *
 * Fully isolated: deptrac allows it to depend only on itself and the Geo kernel.
 * No other topic domain may reach into it, and it reaches into no other topic.
 * Planned layers (built in the confirmed build phase):
 *   - Module/        ForestModule — the tagged ModuleDefinition (KPIs, default charts, caption)
 *   - Domain/        value objects (year range, loss statistics)
 *   - Service/       ForestLossImporter (loads clipped Hansen GFC into PostGIS)
 *   - ApiResource/   API Platform resource — REST + filters + export formats
 *   - LiveComponent/ map + year-slider (Symfony UX)
 *   - Controller/    public map page + export endpoints
 *
 * Data source: Hansen Global Forest Change (pre-computed annual forest-loss raster),
 * clipped to the NCA AreaOfInterest by the offline Python compute step.
 *
 * This is a structural marker only; behaviour arrives in the build phase.
 */
final class Forest
{
}
