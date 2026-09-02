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

namespace Uhifadhi\Tests\Unit\Overview;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Overview\MapLayer;

/**
 * THE SEAM VALIDATES THE SHAPE EVERY PLATE READS.
 *
 * A map layer is the one thing a module hands the host that the host then walks
 * — the plate, the dock and the legend all read `features.features` and the
 * geometry under it. A layer that arrives malformed therefore does not fail
 * where it was built: it fails inside somebody else's template, on the page an
 * area manager opens at 07:00. So the constructor is where the FeatureCollection
 * is checked, with a message naming the layer.
 */
final class MapLayerTest extends TestCase
{
    private const array EMPTY_COLLECTION = ['type' => 'FeatureCollection', 'features' => []];

    public function testALayerWithNothingToDrawIsPerfectlyValid(): void
    {
        $layer = new MapLayer('incidents.closed', 'incidents', 'Incidents', 'Closed this week', '#8a8a8a', self::EMPTY_COLLECTION, count: 0, on: false);

        self::assertSame([], $layer->features['features']);
    }

    public function testAnythingThatIsNotAFeatureCollectionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must carry a GeoJSON FeatureCollection');

        new MapLayer('patrols.tracks', 'patrols', 'Patrols', 'Tracks', '#3f8f5f', ['type' => 'Feature']);
    }

    /**
     * `features` IS NOT OPTIONAL. GeoJSON requires it, and every surface that
     * draws a layer walks it — a collection without one used to reach the dock
     * and fail there, which is the furthest possible place from the module that
     * built it.
     */
    public function testACollectionWithoutItsFeaturesListIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('patrols.tracks');

        new MapLayer('patrols.tracks', 'patrols', 'Patrols', 'Tracks', '#3f8f5f', ['type' => 'FeatureCollection']);
    }

    public function testALayerNamesItselfItsLabelAndTheGroupItsLegendSitsUnder(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MapLayer('', 'patrols', 'Patrols', 'Tracks', '#3f8f5f', self::EMPTY_COLLECTION);
    }

    public function testTheLegendDrawsOnlyTheSwatchStylesItHas(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('which the legend does not draw');

        new MapLayer('patrols.tracks', 'patrols', 'Patrols', 'Tracks', '#3f8f5f', self::EMPTY_COLLECTION, 'dashes');
    }
}
