<?php

declare(strict_types=1);

namespace App\Tests\Functional\Dashboard;

use App\Forest\Factory\ForestLossYearFactory;
use App\Spatial\Factory\AreaOfInterestFactory;
use App\Tests\Functional\AuthenticatedWebTestCase;

/**
 * A module is a self-contained page with its own within-module tabs (Overview /
 * Dataframe / Explore / Method / Settings) — underlined `.atabs`, plus an
 * "All modules" backbtn out to the area. Switching modules is done from the area,
 * not from inside a module. Every tab is a live link; unknown slugs/tabs 404.
 */
final class AreaModuleTest extends AuthenticatedWebTestCase
{
    public function testAModuleRendersItsOwnWithinModuleTabsNotAnAreaSwitcher(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne(['name' => 'Tabbed area']);

        $client->request('GET', '/areas/'.$area->getUuidString().'/wildlife');

        self::assertResponseIsSuccessful();
        // The within-module tab bar — Overview is active on the module root.
        self::assertSelectorTextContains('.atabs a.on', 'Overview');
        // All five tabs are present as links, in order.
        self::assertSelectorTextContains('.atabs a:nth-child(1)', 'Overview');
        self::assertSelectorTextContains('.atabs a:nth-child(2)', 'Dataframe');
        self::assertSelectorTextContains('.atabs a:nth-child(3)', 'Explore');
        self::assertSelectorTextContains('.atabs a:nth-child(4)', 'Method');
        self::assertSelectorTextContains('.atabs a:nth-child(5)', 'Settings');
        // The way back is the "All modules" pill, not an in-page module switcher.
        self::assertSelectorExists('.backbtn');
        self::assertSelectorNotExists('.subnav');
    }

    public function testEachViewTabRoutesAndMarksItselfActive(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne(['name' => 'Tabbed area']);
        $base = '/areas/'.$area->getUuidString().'/landcover';

        foreach (['dataframe' => 'Dataframe', 'explore' => 'Explore', 'method' => 'Method'] as $tab => $label) {
            $client->request('GET', $base.'/'.$tab);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.atabs a.on', $label);
        }
    }

    public function testTheSettingsTabLinksToTheModuleEditSurface(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne(['name' => 'Tabbed area']);

        $client->request('GET', '/areas/'.$area->getUuidString().'/landcover');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists(
            \sprintf('.atabs a[href$="/%s/modules/landcover/edit"]', $area->getUuidString()),
        );
    }

    public function testTheLiveForestModuleRendersItsRealSeriesOnOverview(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne(['name' => 'Forest area']);
        ForestLossYearFactory::createOne(['aoi' => $area, 'year' => 2013, 'areaHa' => 186.0]);

        $client->request('GET', '/areas/'.$area->getUuidString().'/forest');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.atabs a.on', 'Overview');
        self::assertSelectorTextContains('.pg', 'Forest loss');
        self::assertSelectorTextContains('body', 'Annual loss');
        self::assertSelectorTextContains('body', '186');
    }

    public function testATemplateModuleShowsItsScaffoldStateOnOverview(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne(['name' => 'Scaffold area']);

        $client->request('GET', '/areas/'.$area->getUuidString().'/wildlife');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.pg', 'Wildlife');
        self::assertSelectorTextContains('.chip.warn', 'template');
    }

    public function testUnknownModulePlannedModuleAndUnknownTabAll404(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne(['name' => 'Guarded area']);
        $base = '/areas/'.$area->getUuidString();

        // Unknown module.
        $client->request('GET', $base.'/bogus');
        self::assertResponseStatusCodeSame(404);

        // Planned-but-inert module: listed elsewhere, not routable.
        $client->request('GET', $base.'/fires');
        self::assertResponseStatusCodeSame(404);

        // Overview is the area hub, not a module route.
        $client->request('GET', $base.'/overview');
        self::assertResponseStatusCodeSame(404);

        // An unknown tab on a real module is rejected by the route requirement.
        $client->request('GET', $base.'/landcover/bogus');
        self::assertResponseStatusCodeSame(404);
    }
}
