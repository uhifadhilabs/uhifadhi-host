<?php

declare(strict_types=1);

namespace App\Dashboard\Service;

use App\Composition\Entity\Module;
use App\Composition\Enum\ModuleCategory;
use App\Composition\Service\AreaCompositionService;
use App\Dashboard\Module\ModuleRegistry;
use App\Ingestion\Repository\DatasetRepository;
use App\Spatial\Entity\AreaOfInterest;

/**
 * Builds the area's Modules-tab card grid: one content-ful card per active module, grouped by the
 * flux / pressure / biodiversity taxonomy. A module's headline stat is its definition's FIRST KPI
 * (computed in the module's own context); its mini-preview series comes from its first series-kind
 * dataset. Modules without data stay honest — no fabricated numbers. No module is named here.
 */
final readonly class ModuleGridService
{
    /** The zones, in reading order — flux (the ecosystem), pressure (people), then biodiversity/synthesis. */
    private const ZONES = [
        ModuleCategory::Flux->value => 'Flux — what the ecosystem is doing',
        ModuleCategory::Pressure->value => 'Pressure — what people are doing',
        ModuleCategory::Biodiversity->value => 'Biodiversity & synthesis',
    ];

    public function __construct(
        private AreaCompositionService $composition,
        private AreaModuleService $areaModules,
        private ModuleRegistry $registry,
        private DatasetRepository $datasets,
        private ModuleEntryRouteResolver $entryRoutes,
    ) {
    }

    /**
     * The area's active modules as cards, grouped and in zone order. Empty zones are dropped.
     *
     * @return list<array{label: string, cards: list<array{
     *     slug: string, title: string, status: string, source: string, summary: string,
     *     stat: string|null, statSub: string|null, series: list<float>, entryRoute: string|null}>}>
     */
    public function grouped(AreaOfInterest $area): array
    {
        $byZone = [];
        foreach ($this->composition->activeFor($area) as $areaModule) {
            $module = $areaModule->getModule();
            if (null === $module || $module->isPinned()) {
                continue; // the Overview hub is the area's Overview tab, not a module card
            }
            $byZone[$module->getCategory()->value][] = $this->card($area, $module);
        }

        $groups = [];
        foreach (self::ZONES as $categoryValue => $label) {
            if ([] !== ($byZone[$categoryValue] ?? [])) {
                $groups[] = ['label' => $label, 'cards' => $byZone[$categoryValue]];
            }
        }

        return $groups;
    }

    /**
     * @return array{slug: string, title: string, status: string, source: string, summary: string,
     *     stat: string|null, statSub: string|null, series: list<float>, entryRoute: string|null}
     */
    private function card(AreaOfInterest $area, Module $module): array
    {
        $slug = (string) $module->getSlug();

        // Headline stat: the module definition's first KPI, if the module computes any.
        $stat = null;
        $statSub = null;
        $kpis = $this->registry->definitionFor($slug)->kpis($area);
        if ([] !== $kpis) {
            $first = $kpis[0];
            $stat = $first->value.('' !== $first->unit ? ' '.$first->unit : '');
            $statSub = $first->sub;
        }

        // Mini preview: column 1 of the module's first tabular dataset — the (label, value, …)
        // convention every emitted dataframe follows (year→ha, class→area_km2, …).
        $series = [];
        foreach ($this->datasets->forModule($area, $slug) as $dataset) {
            if (!$dataset->getKind()->isTabular()) {
                continue;
            }
            foreach ($dataset->getRows() ?? [] as $row) {
                $value = $row[1] ?? null;
                if (\is_int($value) || \is_float($value)) {
                    $series[] = (float) $value;
                }
            }
            break;
        }

        return [
            'slug' => $slug,
            'title' => (string) $module->getName(),
            'status' => $module->getStatus()->value,
            'source' => (string) $module->getDataSource(),
            'summary' => $this->areaModules->blurb($slug),
            'stat' => $stat,
            'statSub' => $statSub,
            'series' => $series,
            'entryRoute' => $this->entryRoutes->entryRouteFor($slug),
        ];
    }
}
