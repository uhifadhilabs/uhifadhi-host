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

namespace Uhifadhi\Overview;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Model\ZonePalette;
use Uhifadhi\Repository\ZoneRepository;
use Uhifadhi\Service\AreaOverviewCatalogue;

/**
 * THE TWO LAYERS THE HOST ITSELF OWNS: the area's boundary, and its zones.
 *
 * Everything else on the operational plate belongs to a module — today's tracks,
 * the live positions, the open incidents — and arrives through the same
 * interface, which is what makes the plate composable rather than a host map
 * that modules were allowed to scribble on.
 *
 * THE ZONES LAYER IS OFF BY DEFAULT. A zone is a lens, not a fence: it is how
 * the area's own work is READ, and the first question at 07:00 is where people
 * and incidents are, not how the reading is divided. It is one click away, with
 * its legend, exactly where it was.
 *
 * A ZONE'S COLOUR IS DATA, NOT THEME, so it comes from {@see ZonePalette} —
 * the same swatch means the same zone on this plate, on the zones dashboard and
 * in an export.
 */
#[AutoconfigureTag(MapLayerProviderInterface::TAG)]
final readonly class HostMapLayers implements MapLayerProviderInterface
{
    public function __construct(private ZoneRepository $zones)
    {
    }

    public function moduleSlug(): string
    {
        return AreaOverviewCatalogue::HOST_SLUG;
    }

    public function mapLayersFor(AreaOfInterest $area, \DateTimeImmutable $now): array
    {
        $zones = $this->zones->zonesFor($area);

        $zoneFeatures = [];
        foreach ($zones as $index => $zone) {
            $geom = $zone->getGeom();
            if (null === $geom) {
                continue;
            }
            $zoneFeatures[] = [
                'type' => 'Feature',
                'geometry' => json_decode($geom, true),
                'properties' => [
                    'name' => $zone->getName(),
                    'colour' => ZonePalette::color($index),
                ],
            ];
        }

        $boundary = $area->getGeom();

        return [
            new MapLayer(
                'host.boundary',
                AreaOverviewCatalogue::HOST_SLUG,
                'The area',
                'Boundary',
                ZonePalette::AOI,
                self::collection(null === $boundary ? [] : [[
                    'type' => 'Feature',
                    'geometry' => json_decode($boundary, true),
                    'properties' => ['name' => $area->getName()],
                ]]),
                MapLayer::STYLE_BOUNDARY,
            ),
            new MapLayer(
                'host.zones',
                AreaOverviewCatalogue::HOST_SLUG,
                'The area',
                'Zones',
                '#B9C8BD',
                self::collection($zoneFeatures),
                MapLayer::STYLE_LINE,
                count: \count($zoneFeatures),
                on: false,
            ),
        ];
    }

    /**
     * @param list<array<string, mixed>> $features
     *
     * @return array{type: string, features: list<array<string, mixed>>}
     */
    private static function collection(array $features): array
    {
        return ['type' => 'FeatureCollection', 'features' => $features];
    }
}
