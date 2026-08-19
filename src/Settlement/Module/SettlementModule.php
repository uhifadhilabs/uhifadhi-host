<?php

declare(strict_types=1);

namespace Uhifadhi\Settlement\Module;

use Uhifadhi\Spatial\Module\MethodCaption;
use Uhifadhi\Spatial\Module\ModuleDefinition;
use Uhifadhi\Spatial\Module\VizSpec;

/**
 * The Settlement module's definition: default charts over the `builtup_epoch` series, the growth-map
 * palette (settled-early vs newly-settled), and the Method caption. A thin, data-only context — GHSL
 * built-up surface rides the generic engine → Dataset pipeline; the growth classes land as dissolved
 * ModuleFeatures on the generic map layer.
 */
final class SettlementModule extends ModuleDefinition
{
    public function slug(): string
    {
        return 'settlement';
    }

    public function defaultVisualizations(): array
    {
        return [
            new VizSpec('Built-up inside the area', 'line', 'builtup_epoch', x: 'year', y: 'built_km2_in'),
            new VizSpec('Built-up in the 10 km ring', 'area', 'builtup_epoch', x: 'year', y: 'built_km2_ring'),
            new VizSpec('Growth since first epoch (×)', 'step', 'builtup_epoch', x: 'year', y: 'growth_x'),
        ];
    }

    public function mapDatasetKey(): string
    {
        return 'settlement_map';
    }

    public function palette(): array
    {
        // Growth classes, matching the R prototype's map: early blue, encroachment red.
        return [
            'Already settled in 1975' => '#33507a',
            'New settlement by 2020' => '#c81e1e',
        ];
    }

    public function methodCaption(): MethodCaption
    {
        return new MethodCaption(
            measures: 'settlement pressure',
            answers: 'How fast is settlement growing inside the area versus just outside its boundary, and where is it encroaching?',
            takeaway: 'Built-up surface grows far faster in the 10 km ring than inside the boundary — pressure concentrates at the edge, with new settlement clustering along the south-eastern approaches.',
            pipeline: [
                ['step' => '1 · Read', 'detail' => 'five GHSL epochs windowed out of JRC zips (/vsizip · /vsicurl)'],
                ['step' => '2 · Assert', 'detail' => 'CRS = World Mollweide (ESRI:54009) — equal-area, so sums are exact'],
                ['step' => '3 · Zonal', 'detail' => 'built-up m² summed inside the AOI vs the 10 km ring, per epoch'],
                ['step' => '4 · Classes', 'detail' => 'cells ≥ 0.5 ha built: settled by first epoch vs new by last'],
            ],
            pipelineNote: 'Runs in the engine; the outputs land as the <b>builtup_epoch</b> series + the <b>settlement_map</b> growth classes.',
            source: [
                ['label' => 'Dataset', 'value' => 'GHSL GHS-BUILT-S R2023A'],
                ['label' => 'Resolution', 'value' => '1 km · epochs 1975–2020'],
                ['label' => 'Sensor', 'value' => 'Landsat + Sentinel composite'],
                ['label' => 'Access', 'value' => 'JRC open FTP'],
                ['label' => 'Licence', 'value' => 'CC BY 4.0 · open'],
            ],
            sourceNote: 'Cite: Pesaresi &amp; Politis (2023), GHS-BUILT-S R2023A, JRC. Open data — no credentials.',
        );
    }
}
