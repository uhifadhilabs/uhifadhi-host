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
    /** @var list<array{slug: string, label: string, status: string, blurb: string}> */
    private const MODULES = [
        ['slug' => 'overview', 'label' => 'Overview', 'status' => 'live', 'blurb' => 'The park hub.'],
        ['slug' => 'forest', 'label' => 'Forest loss', 'status' => 'live', 'blurb' => 'The real Hansen series: accounting, decomposition, trend.'],
        ['slug' => 'climate', 'label' => 'Climate', 'status' => 'template', 'blurb' => 'WorldClim normals, CHIRPS anomalies, CMIP futures.'],
        ['slug' => 'drought', 'label' => 'Drought', 'status' => 'template', 'blurb' => 'SPEI monitor, drought-class extent, soil-moisture percentiles.'],
        ['slug' => 'vegetation', 'label' => 'Vegetation', 'status' => 'template', 'blurb' => 'NDVI envelopes, Hovmöller, phenology shift.'],
        ['slug' => 'stations', 'label' => 'Stations', 'status' => 'template', 'blurb' => 'Meteograms, wind roses, soil profiles, warming stripes.'],
        ['slug' => 'landcover', 'label' => 'Land cover', 'status' => 'template', 'blurb' => 'WorldCover composition, sankey transitions, cropland creep.'],
        ['slug' => 'anthropogenic', 'label' => 'Anthropogenic', 'status' => 'template', 'blurb' => 'Boundary-buffer encroachment: built-up rings, distance decay, edge pressure.'],
        ['slug' => 'tourism', 'label' => 'Tourism', 'status' => 'template', 'blurb' => 'Camps & lodges monitor: expansion, concentration, displacement.'],
        ['slug' => 'livestock', 'label' => 'Livestock', 'status' => 'template', 'blurb' => 'Herd census trends, grazing pressure, stocking vs capacity.'],
        ['slug' => 'statistics', 'label' => 'Statistics', 'status' => 'template', 'blurb' => 'Fits, uncertainty, diagnostics, PCA — the inferential layer.'],
    ];

    /** @var list<array{slug: string, label: string}> */
    private const PLANNED = [
        ['slug' => 'fires', 'label' => 'Fires'],
        ['slug' => 'water', 'label' => 'Water'],
        ['slug' => 'roads', 'label' => 'Roads'],
    ];

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
