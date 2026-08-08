<?php

declare(strict_types=1);

namespace App\Tests\Functional\Forest;

use App\Forest\Factory\ForestLossYearFactory;
use App\Spatial\Factory\AreaOfInterestFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The dashboard page: shell, stat chips, map wiring and the per-year chart.
 */
final class MapPageTest extends WebTestCase
{
    use Factories;

    public function testTheMapPageRendersTheDashboardWithMapWiring(): void
    {
        $client = static::createClient();
        AreaOfInterestFactory::createOne(['name' => 'Ngorongoro Conservation Area']);
        ForestLossYearFactory::createOne(['year' => 2010, 'areaHa' => 185.0]);
        ForestLossYearFactory::createOne(['year' => 2013, 'areaHa' => 186.0]);

        $crawler = $client->request('GET', '/map');

        self::assertResponseIsSuccessful();
        // The Stimulus map controller with its boundary + API wiring.
        self::assertSelectorExists('[data-controller="map"]');
        self::assertSelectorExists('[data-map-target="canvas"]');
        $wrapper = $crawler->filter('[data-controller="map"]');
        self::assertStringContainsString('MultiPolygon', (string) $wrapper->attr('data-map-boundary-value'));
        self::assertSame('/api/forest-loss.geojson', $wrapper->attr('data-map-forest-loss-url-value'));
        // Stat chips reflect the seeded data.
        self::assertSelectorTextContains('header', '371 ha');
        self::assertSelectorTextContains('header', 'Ngorongoro Conservation Area');
        // One chart bar per loss year.
        self::assertCount(2, $crawler->filter('[data-map-target="bar"]'));
    }

    public function testTheMapPageStillRendersWithAnEmptyDatabase(): void
    {
        $client = static::createClient();

        $client->request('GET', '/map');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-map-target="canvas"]');
    }
}
