<?php

declare(strict_types=1);

namespace App\LandCover\Module;

use App\Spatial\Module\MethodCaption;
use App\Spatial\Module\ModuleDefinition;
use App\Spatial\Module\VizSpec;

/**
 * The Land-cover module's definition: default charts over the `landcover_class` dataframe, the
 * WorldCover palette for its map layer + legend, and the Method caption. A thin, data-only context —
 * its data rides the generic engine → Dataset pipeline; KPIs derive generically from the dataframe.
 */
final class LandCoverModule extends ModuleDefinition
{
    public function slug(): string
    {
        return 'landcover';
    }

    public function defaultVisualizations(): array
    {
        return [
            new VizSpec('Class areas', 'bar', 'landcover_class', x: 'class', y: 'area_km2'),
            new VizSpec('Fragmentation', 'bar', 'landcover_class', x: 'class', y: 'patch_density'),
        ];
    }

    public function palette(): array
    {
        // ESA WorldCover class → colour (label variants included, matching the engine's labels).
        return [
            'Grassland' => '#f5e07a',
            'Shrubland' => '#b8a600',
            'Tree cover' => '#1a6b34',
            'Water' => '#2b7fd6',
            'Bare/sparse' => '#b0aead',
            'Bare / sparse vegetation' => '#b0aead',
            'Cropland' => '#e59b3a',
            'Built-up' => '#c81e1e',
            'Herb. wetland' => '#5ad3c8',
            'Herbaceous wetland' => '#5ad3c8',
            'Mangrove' => '#0b6e5e',
            'Mangroves' => '#0b6e5e',
            'Moss/lichen' => '#f2f0d0',
            'Snow/ice' => '#ffffff',
        ];
    }

    public function methodCaption(): MethodCaption
    {
        return new MethodCaption(
            measures: 'land cover',
            answers: 'What habitats make up this area, and where are they?',
            takeaway: 'Grassland dominates (77%) with forest confined to the Crater Highlands (6%); cropland (0.2%) presses in only along the south-eastern edge.',
            pipeline: [
                ['step' => '1 · Clip', 'detail' => 'WorldCover → AOI cutline (/vsicurl)'],
                ['step' => '2 · Reproject', 'detail' => 'UTM 36S · 30 m · mode-resampled'],
                ['step' => '3 · Areas', 'detail' => 'pixel counts × cell area → class km²'],
                ['step' => '4 · Fragmentation', 'detail' => 'patch / edge density (scipy.ndimage)'],
            ],
            pipelineNote: 'Runs in the engine; the outputs land as the <b>landcover_class</b> dataframe + the map layer.',
            source: [
                ['label' => 'Dataset', 'value' => 'ESA WorldCover 2021 v200'],
                ['label' => 'Resolution', 'value' => '10 m (→ 30 m for the AOI)'],
                ['label' => 'Sensor', 'value' => 'Sentinel-1 + Sentinel-2'],
                ['label' => 'Accuracy', 'value' => '~76% overall'],
                ['label' => 'Licence', 'value' => 'CC BY 4.0 · open'],
            ],
            sourceNote: 'Cite: Zanaga et al. (2022), ESA WorldCover 10 m 2021 v200. Open data — no credentials.',
        );
    }
}
