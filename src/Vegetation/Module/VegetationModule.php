<?php

declare(strict_types=1);

namespace App\Vegetation\Module;

use App\Spatial\Module\MethodCaption;
use App\Spatial\Module\ModuleDefinition;
use App\Spatial\Module\VizSpec;

/**
 * The Vegetation module's definition: default charts over the `phenology_16day` series and the
 * Method caption. A thin, data-only context — MODIS NDVI rides the generic engine → Dataset
 * pipeline, and its peak-greenness surface is the raster path's first real consumer (the engine
 * emits a coloured PNG + bounds; the map serves it as an ImageOverlay).
 */
final class VegetationModule extends ModuleDefinition
{
    public function slug(): string
    {
        return 'vegetation';
    }

    public function defaultVisualizations(): array
    {
        return [
            new VizSpec('Phenology — median NDVI', 'line', 'phenology_16day', x: 'doy', y: 'ndvi_median'),
            new VizSpec('Greenness ceiling (p90)', 'area', 'phenology_16day', x: 'doy', y: 'ndvi_p90'),
            new VizSpec('Seasonal trend (LOESS)', 'lowess', 'phenology_16day', x: 'doy', y: 'ndvi_median'),
        ];
    }

    public function methodCaption(): MethodCaption
    {
        return new MethodCaption(
            measures: 'vegetation greenness',
            answers: 'How does vegetation green-up and brown-down move through the year, and where does the landscape peak?',
            takeaway: 'A single wet-season greening wave: NDVI climbs from the short rains, peaks after the long rains, then browns down through the dry season — the peak-NDVI surface maps where that greening concentrates.',
            pipeline: [
                ['step' => '1 · Search', 'detail' => "the year's MOD13Q1 composites (STAC · Planetary Computer)"],
                ['step' => '2 · Clip', 'detail' => 'warp each composite to the AOI grid (~250 m, WGS84)'],
                ['step' => '3 · Scale', 'detail' => 'integer NDVI ÷10⁴ (or ÷10⁸) · mask the −3000 fill'],
                ['step' => '4 · Reduce', 'detail' => 'per date: spatial median + p10–p90 · per pixel: annual max'],
            ],
            pipelineNote: 'Runs in the engine; the outputs land as the <b>phenology_16day</b> series + the <b>ndvi_peak</b> raster overlay.',
            source: [
                ['label' => 'Dataset', 'value' => 'MODIS MOD13Q1 v6.1'],
                ['label' => 'Resolution', 'value' => '250 m · 16-day composites'],
                ['label' => 'Sensor', 'value' => 'Terra MODIS'],
                ['label' => 'Access', 'value' => 'Planetary Computer STAC'],
                ['label' => 'Licence', 'value' => 'NASA LP DAAC · open'],
            ],
            sourceNote: 'Cite: Didan (2021), MOD13Q1 v6.1, NASA LP DAAC. Open data — anonymous SAS signing.',
        );
    }
}
