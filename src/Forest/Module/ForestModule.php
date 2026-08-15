<?php

declare(strict_types=1);

namespace App\Forest\Module;

use App\Forest\Service\ForestLossSummaryService;
use App\Spatial\Entity\AreaOfInterest;
use App\Spatial\Module\Kpi;
use App\Spatial\Module\MethodCaption;
use App\Spatial\Module\ModuleDefinition;
use App\Spatial\Module\VizSpec;

/**
 * The Forest module's definition — its Overview KPIs (computed HERE, by Forest's own services, never
 * in the generic dashboard), its default charts over the generic `forest_loss_year` series, and its
 * Method caption. Hansen GFC is the module's (first) data source, not its name.
 */
final class ForestModule extends ModuleDefinition
{
    public function __construct(
        private readonly ForestLossSummaryService $loss,
    ) {
    }

    public function slug(): string
    {
        return 'forest';
    }

    public function kpis(AreaOfInterest $area): array
    {
        $f = $this->loss->forArea($area);
        $hasSeries = [] !== $f['lossByYear'];

        return [
            new Kpi(
                'FL·01',
                'Forest lost',
                $f['totalHa'] > 0 ? number_format(round($f['totalHa'])) : '—',
                $f['totalHa'] > 0 ? 'ha' : '',
                null !== $f['yearFrom'] ? \sprintf('%d–%d · real', $f['yearFrom'], $f['yearTo']) : 'not ingested',
            ),
            new Kpi(
                'FL·02',
                'Worst year',
                null !== $f['worstYear'] ? (string) $f['worstYear'] : '—',
                '',
                null !== $f['worstYear'] ? \sprintf('%s ha · ex-2001', number_format(round($f['worstHa']))) : 'awaiting ingestion',
                hot: true,
            ),
            new Kpi('FL·03', 'Years in series', (string) \count($f['lossByYear']), '', 'forest_loss_year rows'),
            new Kpi(
                'FL·04',
                'Peak year',
                $hasSeries && $f['maxHa'] > 0 ? number_format(round($f['maxHa'])) : '—',
                $hasSeries && $f['maxHa'] > 0 ? 'ha' : '',
                'single-year maximum',
            ),
        ];
    }

    public function defaultVisualizations(): array
    {
        return [
            new VizSpec('Annual loss', 'bar', 'forest_loss_year', x: 'year', y: 'ha'),
            new VizSpec('Cumulative loss', 'area', 'forest_loss_year', x: 'year', y: 'cumulative_ha'),
            new VizSpec('Loss trend', 'lowess', 'forest_loss_year', x: 'year', y: 'ha'),
        ];
    }

    public function methodCaption(): MethodCaption
    {
        return new MethodCaption(
            measures: 'forest loss',
            answers: 'How much tree cover is this area losing, and is the rate accelerating or slowing?',
            takeaway: 'Loss concentrates along the forest edge; the running total is flattening — the loss rate has slowed since its 2013 peak.',
            pipeline: [
                ['step' => '1 · Clip', 'detail' => 'Hansen GFC lossyear tiles → AOI cutline'],
                ['step' => '2 · Polygonize', 'detail' => 'loss pixels → per-year polygons'],
                ['step' => '3 · Dissolve', 'detail' => 'PostGIS ST_Union per year → forest_loss_year'],
                ['step' => '4 · Series', 'detail' => 'per-year ha + running total → the generic store'],
            ],
            pipelineNote: 'The outputs land as the <b>forest_loss_year</b> series + the per-year map layer.',
            source: [
                ['label' => 'Dataset', 'value' => 'Hansen GFC (UMD/Google)'],
                ['label' => 'Resolution', 'value' => '30 m'],
                ['label' => 'Span', 'value' => '2001 – latest release'],
                ['label' => 'Caveat', 'value' => '2001 carries a baseline artifact'],
                ['label' => 'Licence', 'value' => 'CC BY 4.0 · open'],
            ],
            sourceNote: 'Cite: Hansen et al. (2013), High-Resolution Global Maps of 21st-Century Forest Cover Change. The 2001 artifact is annotated, never silently squashed.',
        );
    }
}
