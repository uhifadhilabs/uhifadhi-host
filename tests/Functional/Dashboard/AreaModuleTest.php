<?php

declare(strict_types=1);

namespace App\Tests\Functional\Dashboard;

use App\Forest\Factory\ForestLossYearFactory;
use App\Spatial\Factory\AreaOfInterestFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The area sub-app tabs: every module is a live link (Overview → the hub), the
 * live Forest module renders its real series, template modules show a scaffold,
 * and unknown / planned slugs 404.
 */
final class AreaModuleTest extends WebTestCase
{
    use Factories;

    public function testEverySubNavTabIsALiveLinkNotDeadText(): void
    {
        $client = static::createClient();
        $area = AreaOfInterestFactory::createOne(['name' => 'Tabbed area']);

        $client->request('GET', '/areas/'.$area->getUuidString());

        self::assertResponseIsSuccessful();
        // Overview is the active tab; the other modules are real links.
        self::assertSelectorTextContains('.subnav a.on', 'Overview');
        self::assertSelectorExists(\sprintf('.subnav a[href$="/%s/climate"]', $area->getUuidString()));
        self::assertSelectorExists(\sprintf('.subnav a[href$="/%s/statistics"]', $area->getUuidString()));
        // Planned modules stay inert (no page yet).
        self::assertSelectorTextContains('.subnav span.off', 'Fires');
        // Eleven module links (Overview + ten), so no module tab was dropped to text.
        self::assertGreaterThanOrEqual(11, $client->getCrawler()->filter('.subnav a')->count());
    }

    public function testTheLiveForestModuleRendersItsRealSeries(): void
    {
        $client = static::createClient();
        $area = AreaOfInterestFactory::createOne(['name' => 'Forest area']);
        ForestLossYearFactory::createOne(['aoi' => $area, 'year' => 2013, 'areaHa' => 186.0]);

        $client->request('GET', '/areas/'.$area->getUuidString().'/forest');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.subnav a.on', 'Forest loss');
        self::assertSelectorTextContains('body', 'Annual loss');
        self::assertSelectorTextContains('body', '186');
    }

    public function testATemplateModuleShowsItsScaffoldState(): void
    {
        $client = static::createClient();
        $area = AreaOfInterestFactory::createOne(['name' => 'Scaffold area']);

        $client->request('GET', '/areas/'.$area->getUuidString().'/climate');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.subnav a.on', 'Climate');
        self::assertSelectorTextContains('.chip.warn', 'template');
    }

    public function testUnknownAndPlannedModulesTriggerA404(): void
    {
        $client = static::createClient();
        $area = AreaOfInterestFactory::createOne(['name' => 'Guarded area']);

        $client->request('GET', '/areas/'.$area->getUuidString().'/bogus');
        self::assertResponseStatusCodeSame(404);

        // Planned tabs are listed but not routable.
        $client->request('GET', '/areas/'.$area->getUuidString().'/fires');
        self::assertResponseStatusCodeSame(404);

        // The Overview tab is the hub, not a module route.
        $client->request('GET', '/areas/'.$area->getUuidString().'/overview');
        self::assertResponseStatusCodeSame(404);
    }
}
