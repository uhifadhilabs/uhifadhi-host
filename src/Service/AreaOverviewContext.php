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

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Uhifadhi\Entity\AreaOfInterest;

/**
 * EXACTLY WHAT EVERY PARTIAL ON THE AREA OVERVIEW RECEIVES — the dashboard's and
 * the library's, from one call, so a plate can never read differently in the
 * two.
 *
 * A CONTRIBUTOR'S OWN VARIABLES ARE UNDER ITS SLUG (`by.patrols`), which is how
 * twenty widgets from three owners share one context without a name collision
 * and without the host having to know what any of them mean. The merged parts —
 * the strip's tiles, the attention list, the map's layers and legend, the pulse
 * — are shared, because they are the host's own widgets' content.
 *
 * IT IS A SERVICE RATHER THAN A CONTROLLER METHOD because a template is only
 * proved by being DRAWN, and drawing one honestly means handing it the context
 * the page hands it. {@see \Uhifadhi\Tests\Integration\Overview\OverviewPartialRenderTest}
 * renders every widget on the surface through this, so a partial that reads a
 * key this never puts here fails in the suite instead of in production.
 */
final readonly class AreaOverviewContext
{
    public function __construct(
        private AreaOverviewCatalogue $catalogue,
        private AreaOverviewComposer $composer,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param list<string>            $installed the area's module slugs, in its own order
     * @param \DateTimeImmutable|null $now       handed in where a caller needs a fixed moment
     *
     * @return array<string, mixed>
     */
    public function for(AreaOfInterest $area, array $installed, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();

        $by = [];
        $contributions = [];
        foreach ($this->catalogue->contributorsFor($installed) as $contributor) {
            $slug = $contributor->moduleSlug();
            $by[$slug] = $contributor->context($area, $now);
            $contributions[$slug] = ['widgets' => \count($contributor->widgets()), 'tiles' => 0, 'attention' => 0, 'layers' => 0];
        }

        $attention = $this->composer->attention($area, $installed, $now);
        $layers = $this->composer->mapLayers($area, $installed, $now);
        $uuid = ['uuid' => $area->getUuidString()];
        $tiles = [
            ...$this->composer->nowTiles($area, $installed, $now),
            ...$this->composer->hostSummaryTiles($attention, $this->urls->generate('dashboard_area_show', $uuid)),
        ];

        // WHAT EACH MODULE PUT ON THIS PAGE, COUNTED rather than described — a
        // module whose provider went quiet says so on the "Modules in this area"
        // card instead of still claiming three tiles it no longer returns.
        foreach ([['tiles', $tiles], ['attention', $attention], ['layers', $layers]] as [$kind, $parts]) {
            foreach ($parts as $part) {
                if (isset($contributions[$part->moduleSlug])) {
                    ++$contributions[$part->moduleSlug][$kind];
                }
            }
        }

        return [
            'area' => $area,
            'now' => $now,
            'by' => $by,
            'tiles' => $tiles,
            'contributions' => $contributions,
            'attention' => $attention,
            'layers' => $layers,
            'legend' => $this->composer->legend($layers),
            'pulse' => $this->composer->pulse($area, $installed, $now),
            'urls' => [
                'settings' => $this->urls->generate('dashboard_area_settings', $uuid),
                'modules' => $this->urls->generate('dashboard_area_modules_grid', $uuid),
                'customize' => $this->urls->generate('dashboard_area_modules', $uuid),
                'zones' => $this->urls->generate('app_area_zones', $uuid),
            ],
        ];
    }
}
