<?php

declare(strict_types=1);

namespace App\Dashboard\Service;

/**
 * The per-area analytical modules that make up the sub-app sub-nav: their slug,
 * label, status and one-line blurb, in module order. `live` modules render real
 * data; `template` modules are scaffolded and light up when their ingestion
 * lands; `planned` modules are inert (no page yet). One source for the
 * sub-nav and the module router, so a module can never point at a page that 404s.
 */
final class AreaModuleService
{
    // Trimmed to the modules covering the six Ngorongoro research questions
    // (see the NGORONGORO_MODULE_DATA_MAP). Covariate/extra modules — Climate,
    // Drought, Stations, Livestock, Fires — are deferred to the future "add module"
    // catalog and intentionally not shown here.
    /** @var list<array{slug: string, label: string, status: string, blurb: string}> */
    private const MODULES = [
        ['slug' => 'overview', 'label' => 'Overview', 'status' => 'live', 'blurb' => 'The park hub.'],
        // Flux — what the ecosystem does
        ['slug' => 'forest', 'label' => 'Forest loss', 'status' => 'live', 'blurb' => 'The real Hansen series: accounting, decomposition, trend.'],
        ['slug' => 'structure', 'label' => 'Forest structure', 'status' => 'template', 'blurb' => 'Canopy height & above-ground biomass — GEDI/CCI/Meta (Q4, the LiDAR objective).'],
        ['slug' => 'vegetation', 'label' => 'Vegetation', 'status' => 'template', 'blurb' => 'NDVI/phenology & spectral composition → species richness (Q2).'],
        ['slug' => 'landcover', 'label' => 'Land cover', 'status' => 'template', 'blurb' => 'WorldCover composition, transitions, fragmentation (Q1).'],
        ['slug' => 'water', 'label' => 'Water', 'status' => 'template', 'blurb' => 'JRC surface water, seasonality, distance-to-water — the wildlife covariate (Q3).'],
        // Pressure — what people do
        ['slug' => 'anthropogenic', 'label' => 'Anthropogenic', 'status' => 'template', 'blurb' => 'Settlement expansion & boundary-buffer encroachment; edge pressure (Q1).'],
        ['slug' => 'tourism', 'label' => 'Tourism', 'status' => 'template', 'blurb' => 'Camps & lodges monitor; visitor routing & safety (Q5).'],
        ['slug' => 'roads', 'label' => 'Roads', 'status' => 'template', 'blurb' => 'OSM/GRIP network, routing & access, fragmentation (Q5).'],
        // Biodiversity & synthesis
        ['slug' => 'wildlife', 'label' => 'Wildlife', 'status' => 'template', 'blurb' => 'Animal-distribution SDM & invasive-species risk from RS covariates + occurrences (Q3).'],
        ['slug' => 'statistics', 'label' => 'Statistics', 'status' => 'template', 'blurb' => 'Fits, uncertainty, diagnostics, PCA — the inferential layer (Q6).'],
    ];

    /** @var list<array{slug: string, label: string}> */
    private const PLANNED = [];

    /** @return list<array{slug: string, label: string, status: string, blurb: string}> */
    public function modules(): array
    {
        return self::MODULES;
    }

    /** @return list<array{slug: string, label: string}> */
    public function planned(): array
    {
        return self::PLANNED;
    }

    /**
     * A routable module page (everything except the Overview hub, which is the
     * area show page). Returns null for the hub, planned modules, or unknown slugs
     * — the caller 404s on null.
     *
     * @return array{slug: string, label: string, status: string, blurb: string}|null
     */
    public function page(string $slug): ?array
    {
        if ('overview' === $slug) {
            return null;
        }

        foreach (self::MODULES as $module) {
            if ($module['slug'] === $slug) {
                return $module;
            }
        }

        return null;
    }
}
