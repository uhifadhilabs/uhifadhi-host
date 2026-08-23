<?php

declare(strict_types=1);

/*
 * This file is part of uhifadhi.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Service;

use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\Module;
use Uhifadhi\Enum\ModuleCategory;

/**
 * Builds the area's Modules-tab card grid: one content-ful card per active module, grouped by the
 * flux / pressure / biodiversity taxonomy. A card carries only what the host can know — catalogue
 * identity only. Everything richer lives on the module's own pages (its bundle). No module is named here.
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
        private ModuleEntryRouteResolver $entryRoutes,
    ) {
    }

    /**
     * The area's active modules as cards, grouped and in zone order. Empty zones are dropped.
     *
     * @return list<array{label: string, cards: list<array{
     *     slug: string, title: string, status: string, source: string,
     *     entryRoute: string|null}>}>
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
     * @return array{slug: string, title: string, status: string, source: string,
     *     entryRoute: string|null}
     */
    private function card(AreaOfInterest $area, Module $module): array
    {
        $slug = (string) $module->getSlug();

        return [
            'slug' => $slug,
            'title' => (string) $module->getName(),
            'status' => $module->getStatus()->value,
            'source' => (string) $module->getDataSource(),
            'entryRoute' => $this->entryRoutes->entryRouteFor($slug),
        ];
    }
}
