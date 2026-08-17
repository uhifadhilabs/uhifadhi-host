<?php

declare(strict_types=1);

namespace App\Statistics\Module;

use App\Spatial\Entity\AreaOfInterest;
use App\Spatial\Module\Kpi;
use App\Spatial\Module\MethodCaption;
use App\Spatial\Module\ModuleDefinition;
use App\Statistics\Service\SynthesisService;

/**
 * The Statistics module's definition — the Q6 scorecard. The proof of the architecture's last
 * claim: this module has NO engine module and NO source of its own; its KPIs and dataframes are
 * derived entirely from the other modules' stored datasets, refreshed on every succeeded run.
 */
final class StatisticsModule extends ModuleDefinition
{
    public function __construct(
        private readonly SynthesisService $synthesis,
    ) {
    }

    public function slug(): string
    {
        return 'statistics';
    }

    public function kpis(AreaOfInterest $area): array
    {
        // The scorecard: one headline tile per objective, first four indicators with a value.
        $kpis = [];
        foreach ($this->synthesis->indicators($area) as $i => [$objective, $indicator, $value, $unit, $source]) {
            if (null === $value || 'Cross-check' === $objective) {
                continue;
            }
            $kpis[] = new Kpi(
                \sprintf('SY·%02d', $i + 1),
                $objective,
                $value >= 100 ? number_format(round($value)) : rtrim(rtrim(number_format($value, 2), '0'), '.'),
                $unit,
                $indicator,
            );
            if (4 === \count($kpis)) {
                break;
            }
        }

        return $kpis;
    }

    public function methodCaption(): MethodCaption
    {
        return new MethodCaption(
            measures: 'the integrated synthesis',
            answers: 'What does the whole observatory say across all objectives at a glance — and how sure are we of each number?',
            takeaway: 'The pressures (settlement, roads, cropland) sit at the south-eastern boundary while the core grassland–forest system remains intact — the boundary is where monitoring effort belongs.',
            pipeline: [
                ['step' => '1 · Read', 'detail' => "every module's stored dataframes for this area (no new source)"],
                ['step' => '2 · Derive', 'detail' => 'one headline indicator per research objective'],
                ['step' => '3 · Provenance', 'detail' => "each module's dataset, licence and key caveat, from its own Method"],
                ['step' => '4 · Refresh', 'detail' => 'automatically after every succeeded module run — always current'],
            ],
            pipelineNote: 'No engine module. The outputs land as the <b>synthesis</b> + <b>provenance</b> dataframes.',
            source: [
                ['label' => 'Dataset', 'value' => "the other modules' datasets"],
                ['label' => 'Engine', 'value' => 'none — derived in-app'],
                ['label' => 'Freshness', 'value' => 'auto, on every succeeded run'],
                ['label' => 'Caveat', 'value' => 'inherits each layer\'s limits'],
                ['label' => 'Licence', 'value' => 'follows the source layers'],
            ],
            sourceNote: 'Each headline number inherits the limitations of its layer — see the provenance dataframe and the per-module Method tabs.',
        );
    }
}
