<?php

declare(strict_types=1);

namespace App\Dashboard\Service;

/**
 * The Method-tab content per module — the analysis caption (what it answers, the takeaway, how it is
 * computed, and the data source) that travels with each figure from the R prototype into the app. It
 * is documentation, not row data, so it renders whether or not the module's ingestion has run yet.
 * Modules without a caption yet return null and fall back to the pending shell.
 */
final readonly class ModuleMethodService
{
    /**
     * @return array{
     *     measures: string,
     *     answers: string,
     *     takeaway: string,
     *     pipeline: list<array{step: string, detail: string}>,
     *     pipelineNote: string,
     *     source: list<array{label: string, value: string}>,
     *     sourceNote: string
     * }|null
     */
    public function forModule(string $slug): ?array
    {
        return self::CAPTIONS[$slug] ?? null;
    }

    /** @var array<string, array{measures: string, answers: string, takeaway: string, pipeline: list<array{step: string, detail: string}>, pipelineNote: string, source: list<array{label: string, value: string}>, sourceNote: string}> */
    private const CAPTIONS = [
        'landcover' => [
            'measures' => 'land cover',
            'answers' => 'What habitats make up this area, and where are they?',
            'takeaway' => 'Grassland dominates (77%) with forest confined to the Crater Highlands (6%); cropland (0.2%) presses in only along the south-eastern edge.',
            'pipeline' => [
                ['step' => '1 · Clip', 'detail' => 'WorldCover → AOI cutline (/vsicurl)'],
                ['step' => '2 · Reproject', 'detail' => 'UTM 36S · 30 m · mode-resampled'],
                ['step' => '3 · Areas', 'detail' => 'pixel counts × cell area → class km²'],
                ['step' => '4 · Fragmentation', 'detail' => 'patch / edge density (scipy.ndimage)'],
            ],
            'pipelineNote' => 'Runs in the engine; the outputs land as the <b>landcover_class</b> dataframe + the map layer.',
            'source' => [
                ['label' => 'Dataset', 'value' => 'ESA WorldCover 2021 v200'],
                ['label' => 'Resolution', 'value' => '10 m (→ 30 m for the AOI)'],
                ['label' => 'Sensor', 'value' => 'Sentinel-1 + Sentinel-2'],
                ['label' => 'Accuracy', 'value' => '~76% overall'],
                ['label' => 'Licence', 'value' => 'CC BY 4.0 · open'],
            ],
            'sourceNote' => 'Cite: Zanaga et al. (2022), ESA WorldCover 10 m 2021 v200. Open data — no credentials.',
        ],
    ];
}
