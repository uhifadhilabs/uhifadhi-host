<?php

declare(strict_types=1);

namespace App\Tests\Functional\Dashboard;

use App\Forest\Factory\ForestLossYearFactory;
use App\Spatial\Factory\AreaOfInterestFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The home page: every area with its size, loss metrics and last-run status.
 */
final class AreaIndexTest extends WebTestCase
{
    use Factories;

    public function testAnEmptyPlatformInvitesTheFirstUpload(): void
    {
        $client = static::createClient();

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main, body', 'No areas yet');
        self::assertSelectorExists(\sprintf('a[href="%s"]', '/areas/new'));
    }

    public function testAreasAreListedWithTheirMetrics(): void
    {
        $client = static::createClient();
        $aoi = AreaOfInterestFactory::createOne(['name' => 'Listed area']);
        ForestLossYearFactory::createOne(['aoi' => $aoi, 'year' => 2010, 'areaHa' => 185.0]);
        ForestLossYearFactory::createOne(['aoi' => $aoi, 'year' => 2013, 'areaHa' => 186.0]);
        AreaOfInterestFactory::createOne(['name' => 'Bare area']);

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Listed area');
        self::assertSelectorTextContains('table', '371 ha');
        self::assertSelectorTextContains('table', 'Bare area');
        self::assertSelectorTextContains('table', 'not ingested');
        // stAreaKm2 renders a real geodesic size for the factory square.
        self::assertSelectorTextContains('table', 'km²');
    }
}
