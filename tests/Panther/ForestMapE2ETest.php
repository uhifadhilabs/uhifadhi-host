<?php

declare(strict_types=1);

namespace App\Tests\Panther;

use App\Forest\Factory\ForestLossYearFactory;
use App\Spatial\Factory\AreaOfInterestFactory;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;

/**
 * The whole vertical in a real Chrome: Leaflet boots, the boundary + loss
 * GeoJSON render as SVG overlays, and the base-map toggle switches tile sources.
 */
#[SkipDatabaseRollback]
final class ForestMapE2ETest extends E2ETestCase
{
    public function testTheMapRendersBoundaryAndLossAndTogglesTheBase(): void
    {
        AreaOfInterestFactory::createOne(['name' => 'E2E boundary']);
        ForestLossYearFactory::createOne(['year' => 2010, 'areaHa' => 185.0]);

        $client = static::createPantherClient();
        $client->request('GET', '/map');

        // Leaflet booted and attached to the canvas.
        $client->waitFor('.leaflet-container', 10);
        // The boundary (2 layers: casing + line) and the fetched loss feature all
        // render as SVG paths in the overlay pane.
        $client->waitFor('.leaflet-overlay-pane svg path', 10);
        $paths = $client->getCrawler()->filter('.leaflet-overlay-pane svg path');
        self::assertGreaterThanOrEqual(3, $paths->count());

        // Satellite is the default base…
        self::assertSelectorExists('.leaflet-tile-pane img[src*="arcgisonline"]');

        // …and the panel toggle switches to OSM.
        $client->getCrawler()->filter('button[data-base="osm"]')->click();
        $client->waitFor('.leaflet-tile-pane img[src*="openstreetmap"]', 10);
        self::assertSelectorExists('.leaflet-tile-pane img[src*="openstreetmap"]');

        // The panel is above the map and shows the seeded chart bar.
        self::assertSelectorExists('[data-map-target="bar"][data-year="2010"]');
    }
}
