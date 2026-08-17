<?php

declare(strict_types=1);

namespace App\Structure\Module;

use App\Spatial\Module\MethodCaption;
use App\Spatial\Module\ModuleDefinition;
use App\Spatial\Module\VizSpec;

/**
 * The Forest-structure module's definition: the proxy-agreement scatter over the
 * `structure_agreement` dataframe and the Method caption. A thin, data-only context —
 * CCI biomass × GLAD canopy height ride the generic engine → Dataset pipeline, each
 * as a raster ImageOverlay with its own legend.
 */
final class StructureModule extends ModuleDefinition
{
    public function slug(): string
    {
        return 'structure';
    }

    public function defaultVisualizations(): array
    {
        return [
            new VizSpec('Canopy height vs biomass', 'scatter', 'structure_agreement', x: 'canopy_m', y: 'agb_mgha'),
            new VizSpec('Biomass distribution', 'histogram', 'structure_agreement', x: 'agb_mgha', y: 'agb_mgha'),
        ];
    }

    public function methodCaption(): MethodCaption
    {
        return new MethodCaption(
            measures: 'forest structure',
            answers: 'How much biomass is there, how tall is the forest — and can the two open proxies be trusted where they agree?',
            takeaway: 'Two independent estimates rank places consistently — the carbon sits in the Crater Highlands forests where the canopy is tallest. Reliable for landscape patterns and priorities, not exact tonnage.',
            pipeline: [
                ['step' => '1 · Read', 'detail' => 'CCI biomass 2020 + GLAD height 2019, windowed (/vsicurl)'],
                ['step' => '2 · Clean', 'detail' => 'drop height > 60 m (the 101/102 water/no-data codes)'],
                ['step' => '3 · Compare', 'detail' => 'forest cells only (both > 0) → Pearson · Spearman · slope'],
                ['step' => '4 · Sample', 'detail' => '≤ 5,000 cells → the agreement scatter'],
            ],
            pipelineNote: 'Runs in the engine; the outputs land as the <b>structure_agreement</b> + <b>structure_stats</b> dataframes and the <b>biomass</b> + <b>canopy_height</b> overlays.',
            source: [
                ['label' => 'Dataset', 'value' => 'ESA CCI Biomass v4 · GLAD height 2019'],
                ['label' => 'Resolution', 'value' => '100 m · 30 m (→ 100 m grid)'],
                ['label' => 'Years', 'value' => '2020 · 2019 (different!)'],
                ['label' => 'Caveat', 'value' => 'agreement is not ground truth'],
                ['label' => 'Licence', 'value' => 'open · CC BY 4.0'],
            ],
            sourceNote: 'Cite: Santoro et al. (CCI Biomass v4); Potapov et al. (2021) GLAD Forest Height. Height underestimates tall canopy; different sensors, years and models.',
        );
    }
}
