<?php

declare(strict_types=1);

namespace App\Wildlife\Module;

use App\Spatial\Module\MethodCaption;
use App\Spatial\Module\ModuleDefinition;
use App\Spatial\Module\VizSpec;

/**
 * The Wildlife module's definition: default charts over the SDM driver tables and the Method
 * caption. A thin, data-only context — GBIF sightings + open covariates ride the generic
 * engine → Dataset pipeline; each species' suitability surface lands as a raster ImageOverlay.
 */
final class WildlifeModule extends ModuleDefinition
{
    public function slug(): string
    {
        return 'wildlife';
    }

    public function defaultVisualizations(): array
    {
        return [
            new VizSpec('Elephant habitat — what drives it', 'bar', 'elephant_drivers', x: 'driver', y: 'importance'),
            new VizSpec('Lantana risk — what drives it', 'bar', 'lantana_drivers', x: 'driver', y: 'importance'),
            new VizSpec('Model accuracy (AUC)', 'bar', 'sdm_performance', x: 'common', y: 'auc'),
        ];
    }

    public function methodCaption(): MethodCaption
    {
        return new MethodCaption(
            measures: 'species distribution',
            answers: 'Where is habitat most suitable for key species — and where could an invasive establish?',
            takeaway: 'Elephant habitat scores highest in the wooded south and east, matching where sightings cluster; lantana risk concentrates along the settled, disturbed corridors — the surveillance frontier.',
            pipeline: [
                ['step' => '1 · Sightings', 'detail' => 'GBIF occurrences over the region (thinned to one per km cell)'],
                ['step' => '2 · Covariates', 'detail' => 'WorldClim climate + elevation/slope + distance to water (~1 km)'],
                ['step' => '3 · Model', 'detail' => 'presence vs background · regularized logistic (linear + quadratic)'],
                ['step' => '4 · Evaluate', 'detail' => '70/30 split → AUC · permutation importance per driver'],
            ],
            pipelineNote: 'Runs in the engine; the outputs land as the <b>sdm_performance</b> + per-species <b>drivers</b> dataframes and each species\' <b>suitability</b> overlay.',
            source: [
                ['label' => 'Sightings', 'value' => 'GBIF (open API)'],
                ['label' => 'Climate', 'value' => 'WorldClim v2.1 · 30″'],
                ['label' => 'Water', 'value' => 'JRC Global Surface Water'],
                ['label' => 'Caveat', 'value' => 'road/tourism sampling bias'],
                ['label' => 'Licence', 'value' => 'CC BY 4.0 · open'],
            ],
            sourceNote: 'Cite: GBIF.org occurrence download; Fick &amp; Hijmans (2017) WorldClim 2. Opportunistic records — AUC is optimistic until census data replaces them.',
        );
    }
}
