<?php

declare(strict_types=1);

namespace App\Dashboard\Service;

use App\Composition\Repository\AreaModuleRepository;
use App\Spatial\Entity\AreaOfInterest;

/**
 * The per-area analytical modules that make up the sub-app sub-nav: their slug, label, status and
 * one-line blurb, in order. When an area has been composed (its {@see \App\Composition\Entity\AreaModule}
 * rows exist) the sub-nav follows that — so switching a module on/off or reordering it on the
 * customize page drives the real app. Un-composed areas fall back to {@see self::DEFAULTS}. `live`
 * modules render real data; `template` modules are scaffolded until their ingestion lands.
 */
final readonly class AreaModuleService
{
    public function __construct(
        private AreaModuleRepository $areaModules,
    ) {
    }

    /**
     * The default module set — used for an area that hasn't been composed yet.
     *
     * @var list<array{slug: string, label: string, status: string, blurb: string}>
     */
    private const DEFAULTS = [
        ['slug' => 'overview', 'label' => 'Overview', 'status' => 'live', 'blurb' => 'The park hub.'],
        ['slug' => 'forest', 'label' => 'Forest loss', 'status' => 'live', 'blurb' => 'The real Hansen series: accounting, decomposition, trend.'],
        ['slug' => 'structure', 'label' => 'Forest structure', 'status' => 'template', 'blurb' => 'Canopy height & above-ground biomass — GEDI/CCI/Meta (Q4, the LiDAR objective).'],
        ['slug' => 'vegetation', 'label' => 'Vegetation', 'status' => 'template', 'blurb' => 'NDVI/phenology & spectral composition → species richness (Q2).'],
        ['slug' => 'landcover', 'label' => 'Land cover', 'status' => 'template', 'blurb' => 'WorldCover composition, transitions, fragmentation (Q1).'],
        ['slug' => 'water', 'label' => 'Water', 'status' => 'template', 'blurb' => 'JRC surface water, seasonality, distance-to-water — the wildlife covariate (Q3).'],
        ['slug' => 'anthropogenic', 'label' => 'Anthropogenic', 'status' => 'template', 'blurb' => 'Settlement expansion & boundary-buffer encroachment; edge pressure (Q1).'],
        ['slug' => 'tourism', 'label' => 'Tourism', 'status' => 'template', 'blurb' => 'Camps & lodges monitor; visitor routing & safety (Q5).'],
        ['slug' => 'roads', 'label' => 'Roads', 'status' => 'template', 'blurb' => 'OSM/GRIP network, routing & access, fragmentation (Q5).'],
        ['slug' => 'wildlife', 'label' => 'Wildlife', 'status' => 'template', 'blurb' => 'Animal-distribution SDM & invasive-species risk from RS covariates + occurrences (Q3).'],
        ['slug' => 'statistics', 'label' => 'Statistics', 'status' => 'template', 'blurb' => 'Fits, uncertainty, diagnostics, PCA — the inferential layer (Q6).'],
    ];

    /** One-line blurbs by slug — the module-page subtitle for a composed module. */
    private const BLURBS = [
        'overview' => 'The park hub.',
        'forest' => 'The real Hansen series: accounting, decomposition, trend.',
        'structure' => 'Canopy height & above-ground biomass — GEDI/CCI/Meta (Q4, the LiDAR objective).',
        'vegetation' => 'NDVI/phenology & spectral composition → species richness (Q2).',
        'landcover' => 'WorldCover composition, transitions, fragmentation (Q1).',
        'climate' => 'Rainfall & climate normals — CHIRPS/WorldClim, the phenology driver.',
        'drought' => 'Drought & soil-moisture stress — SPEI, the vegetation-anomaly signal.',
        'water' => 'JRC surface water, seasonality, distance-to-water — the wildlife covariate (Q3).',
        'anthropogenic' => 'Settlement expansion & boundary-buffer encroachment; edge pressure (Q1).',
        'livestock' => 'Grazing pressure & stocking — FAO GLW and census, the rangeland load.',
        'tourism' => 'Camps & lodges monitor; visitor routing & safety (Q5).',
        'roads' => 'OSM/GRIP network, routing & access, fragmentation (Q5).',
        'fires' => 'Active-fire & burned-area history — FIRMS/VIIRS, the disturbance record.',
        'wildlife' => 'Animal-distribution SDM & invasive-species risk from RS covariates + occurrences (Q3).',
        'stations' => 'Field-station feeds & sensors — the ground-truth layer.',
        'statistics' => 'Fits, uncertainty, diagnostics, PCA — the inferential layer (Q6).',
    ];

    /**
     * The area's sub-nav modules, in order — from its composition, or the defaults if uncomposed.
     *
     * @return list<array{slug: string, label: string, status: string, blurb: string}>
     */
    public function modules(AreaOfInterest $area): array
    {
        $composed = [];
        foreach ($this->areaModules->activeForArea($area) as $areaModule) {
            $module = $areaModule->getModule();
            if (null === $module) {
                continue;
            }
            $slug = (string) $module->getSlug();
            $composed[] = [
                'slug' => $slug,
                'label' => (string) $module->getName(),
                'status' => $module->getStatus()->value,
                'blurb' => self::BLURBS[$slug] ?? (string) $module->getDataSource(),
            ];
        }

        return [] !== $composed ? $composed : self::DEFAULTS;
    }

    /**
     * Planned-but-inert modules (none today — the shop handles activation now).
     *
     * @return list<array{slug: string, label: string}>
     */
    public function planned(): array
    {
        return [];
    }

    /**
     * A routable module page for this area (everything except the Overview hub). Returns null for the
     * hub, or a module that isn't on the area — the caller 404s on null.
     *
     * @return array{slug: string, label: string, status: string, blurb: string}|null
     */
    public function page(AreaOfInterest $area, string $slug): ?array
    {
        if ('overview' === $slug) {
            return null;
        }

        foreach ($this->modules($area) as $module) {
            if ($module['slug'] === $slug) {
                return $module;
            }
        }

        return null;
    }
}
