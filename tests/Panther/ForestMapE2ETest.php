<?php

declare(strict_types=1);

namespace App\Tests\Panther;

use App\Forest\Factory\ForestLossYearFactory;
use App\Spatial\Factory\AreaOfInterestFactory;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;

/**
 * The dashboard journey in a real Chrome: the index lists the area, its detail
 * page boots Leaflet with boundary + loss overlays, the base toggle switches
 * tile sources, and the ingestion trigger is armed.
 */
#[SkipDatabaseRollback]
final class ForestMapE2ETest extends E2ETestCase
{
    public function testTheDashboardJourney(): void
    {
        $aoi = AreaOfInterestFactory::createOne(['name' => 'E2E area']);
        ForestLossYearFactory::createOne(['aoi' => $aoi, 'year' => 2010, 'areaHa' => 185.0]);

        $client = static::createPantherClient();

        // Index lists the area with its metrics.
        $client->request('GET', '/');
        self::assertSelectorTextContains('table', 'E2E area');
        // Loss shown as a bare figure under the "loss ha" column header.
        self::assertSelectorTextContains('table', '185');

        // Detail: Leaflet boots, boundary + fetched loss render as SVG overlays.
        $client->request('GET', '/areas/'.$aoi->getUuidString());
        $client->waitFor('.leaflet-container', 10);
        $client->waitFor('.leaflet-overlay-pane svg path', 10);
        self::assertGreaterThanOrEqual(3, $client->getCrawler()->filter('.leaflet-overlay-pane svg path')->count());

        // Satellite is the default base; the toggle switches to OSM.
        self::assertSelectorExists('.leaflet-tile-pane img[src*="arcgisonline"]');
        $client->getCrawler()->filter('button[data-base="osm"]')->click();
        $client->waitFor('.leaflet-tile-pane img[src*="openstreetmap"]', 10);

        // The chart bar and the armed ingestion trigger are present.
        self::assertSelectorExists('[data-map-target="bar"][data-year="2010"]');
        self::assertSelectorExists(\sprintf('form[action="/areas/%s/ingest"] button', $aoi->getUuidString()));
    }
}
