<?php

declare(strict_types=1);

namespace App\Dashboard\Service;

use App\Composition\Entity\Module;
use App\Composition\Enum\ModuleCategory;
use App\Composition\Service\AreaCompositionService;
use App\Forest\Service\ForestLossSummaryService;
use App\Spatial\Entity\AreaOfInterest;

/**
 * Builds the area's Modules-tab card grid: one content-ful card per active module, grouped by the
 * flux / pressure / biodiversity taxonomy. A live module (today, only Forest loss) carries a real
 * headline stat and a mini series to preview; a template module carries only its summary and source
 * — no fabricated numbers, honest about awaiting its ingestion. The pinned Overview hub is the area's
 * Overview tab, so it never appears as a card here.
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
        private ForestLossSummaryService $forestLoss,
    ) {
    }

    /**
     * The area's active modules as cards, grouped and in zone order. Empty zones are dropped.
     *
     * @return list<array{label: string, cards: list<array{
     *     slug: string, title: string, status: string, source: string, summary: string,
     *     stat: int|null, statSub: string|null, series: list<float>}>}>
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
     *     stat: int|null, statSub: string|null, series: list<float>}
     */
    private function card(AreaOfInterest $area, Module $module): array
    {
        $slug = (string) $module->getSlug();
        $card = [
            'slug' => $slug,
            'title' => (string) $module->getName(),
            'status' => $module->getStatus()->value,
            'source' => (string) $module->getDataSource(),
            'summary' => $this->areaModules->blurb($slug),
            'stat' => null,
            'statSub' => null,
            'series' => [],
        ];

        // The one live module previews its real Hansen series; templates stay honest (no numbers).
        if ('forest' === $slug) {
            $forest = $this->forestLoss->forArea($area);
            if ($forest['totalHa'] > 0) {
                $card['stat'] = (int) round($forest['totalHa']);
                $card['statSub'] = \sprintf(
                    'ha lost · %02d–%02d',
                    ((int) $forest['yearFrom']) % 100,
                    ((int) $forest['yearTo']) % 100,
                );
                $card['series'] = array_map(static fn (array $row): float => (float) $row['ha'], $forest['lossByYear']);
            }
        }

        return $card;
    }
}
