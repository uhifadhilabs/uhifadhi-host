<?php

declare(strict_types=1);

namespace App\Roads\Module;

use App\Spatial\Module\MethodCaption;
use App\Spatial\Module\ModuleDefinition;
use App\Spatial\Module\VizSpec;

/**
 * The Roads module's definition: default charts over the `roads_by_class` dataframe, the OSM
 * class palette for its network layer, and the Method caption. A thin, data-only context — the
 * network rides the generic engine → Dataset pipeline (per-class ribbons on the map layer), and
 * its remoteness surface is a raster ImageOverlay.
 */
final class RoadsModule extends ModuleDefinition
{
    public function slug(): string
    {
        return 'roads';
    }

    public function defaultVisualizations(): array
    {
        return [
            new VizSpec('Length by class', 'bar', 'roads_by_class', x: 'class', y: 'length_km'),
            new VizSpec('Network share', 'pie', 'roads_by_class', x: 'class', y: 'length_km'),
        ];
    }

    public function palette(): array
    {
        // OSM highway class → colour, matching the R prototype's map; minor classes muted.
        return [
            'trunk' => '#c81e1e',
            'primary' => '#e5732a',
            'secondary' => '#e0a800',
            'tertiary' => '#7a9e3a',
            'unclassified' => '#9aa39b',
            'residential' => '#8b8f98',
            'service' => '#a7a7ad',
            'track' => '#b09a6e',
            'path' => '#c7bfa5',
        ];
    }

    public function methodCaption(): MethodCaption
    {
        return new MethodCaption(
            measures: 'road access',
            answers: 'What does the road network look like, and which parts of the area are remote for patrols, rescue and tourism?',
            takeaway: 'Most mapped kilometres are unpaved track and path; the maintained spine follows the crater rim and the Karatu corridor, leaving the west and north more than 2 km from any road.',
            pipeline: [
                ['step' => '1 · Fetch', 'detail' => 'all OSM highway ways in the AOI bbox (Overpass API)'],
                ['step' => '2 · Clip', 'detail' => 'intersect with the boundary · geodesic km per class'],
                ['step' => '3 · Distance', 'detail' => 'rasterize the network (~200 m) → distance transform'],
                ['step' => '4 · Remote', 'detail' => 'share of the area farther than 2 km from any road'],
            ],
            pipelineNote: 'Runs in the engine; the outputs land as the <b>roads_by_class</b> + <b>roads_stats</b> dataframes, the class network layer and the <b>remoteness</b> overlay.',
            source: [
                ['label' => 'Dataset', 'value' => 'OpenStreetMap (Overpass)'],
                ['label' => 'Coverage', 'value' => 'community-mapped · varies'],
                ['label' => 'Distance', 'value' => 'straight-line, not travel time'],
                ['label' => 'Access', 'value' => 'Overpass API · no key'],
                ['label' => 'Licence', 'value' => 'ODbL · open'],
            ],
            sourceNote: 'Cite: OpenStreetMap contributors (ODbL). OSM completeness varies — unmapped tracks are not counted.',
        );
    }
}
