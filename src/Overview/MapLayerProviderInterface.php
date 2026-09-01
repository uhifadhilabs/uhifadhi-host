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

use Uhifadhi\Entity\AreaOfInterest;

/**
 * THE SEAM A MODULE PUTS A LAYER ON THE HOST'S OPERATIONAL PLATE THROUGH.
 *
 * One plate, many owners. The host draws the map — Leaflet, self-hosted, the
 * same instrument every map in the product wears — and each layer on it belongs
 * to the module that owns the data. The legend is grouped by contributor, which
 * is the only way a person can tell why a layer vanished.
 *
 * A layer that draws nothing today still ships its legend entry, so the legend
 * is a statement about the plate rather than about this morning's data.
 *
 * Tagged explicitly at both ends, for the reason
 * {@see OverviewContributorInterface} spells out.
 */
interface MapLayerProviderInterface
{
    public const string TAG = 'uhifadhi.map.layer';

    /** The slug of the module these layers belong to; asked only where it is installed. */
    public function moduleSlug(): string;

    /**
     * @return list<MapLayer>
     */
    public function mapLayersFor(AreaOfInterest $area, \DateTimeImmutable $now): array;
}
