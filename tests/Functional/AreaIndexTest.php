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

namespace Uhifadhi\Tests\Functional;

use Uhifadhi\Factory\AreaModuleFactory;
use Uhifadhi\Factory\AreaOfInterestFactory;
use Uhifadhi\Factory\ModuleFactory;

/**
 * The home page: every area with its size, loss metrics and last-run status.
 */
final class AreaIndexTest extends AuthenticatedWebTestCase
{
    public function testAnEmptyPlatformInvitesTheFirstUpload(): void
    {
        $client = static::createClient();
        $this->loginAs($client);

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No areas yet');
        self::assertSelectorExists(\sprintf('a[href="%s"]', '/areas/new'));

        // Survey-plate shell: sidebar with the Areas nav item active + the top-header theme toggle.
        self::assertSelectorTextContains('.side .nav-item.on', 'Areas');
        self::assertSelectorExists('.topbar [data-action="theme#toggle"]');
    }

    public function testAreasAreListedWithTheirMetrics(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $aoi = AreaOfInterestFactory::createOne(['name' => 'Listed area']);
        // One switched-on module makes the area read as live in the register.
        AreaModuleFactory::createOne([
            'area' => $aoi,
            'module' => ModuleFactory::new(['slug' => 'demo', 'name' => 'Demo module']),
            'active' => true,
        ]);
        AreaOfInterestFactory::createOne(['name' => 'Bare area']);

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Listed area');
        self::assertSelectorTextContains('table', 'live · 1 module');
        self::assertSelectorTextContains('table', 'Bare area');
        // An area with nothing switched on reads as queued.
        self::assertSelectorTextContains('table', 'queued');
        // stAreaKm2 renders a real geodesic size for the factory square.
        self::assertSelectorTextContains('table', 'km²');

        // Every filter pill renders with its live count (Listed = live, Bare = queued).
        self::assertSelectorTextContains('.subnav', 'All · 2');
        self::assertSelectorTextContains('.subnav', 'Live data · 1');
        self::assertSelectorTextContains('.subnav', 'Queued · 1');
        // The controls are wired (search + sort), not decorative.
        self::assertSelectorExists('[data-register-target="search"][data-action*="register#search"]');
        self::assertSelectorExists('th[data-sort="km2"][data-action*="register#sortBy"]');
        // Every row shows a visible action + is clickable (not just a tinted title).
        self::assertSelectorTextContains('.open-btn', 'Open');
        self::assertSelectorExists('tr[data-action*="register#open"][data-href]');
    }

    /**
     * A pill that can only ever read "· 0" is furniture, not a filter. "Fire module" and
     * "With alerts" counted nothing the host can count — no module contributes either
     * signal — so they are gone, pills and row data alike, until something ships that
     * can move them off zero.
     */
    public function testTheRegisterCarriesNoAlwaysZeroFilterPills(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        AreaOfInterestFactory::createOne(['name' => 'Listed area']);

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-register-target="pill"][data-filter="fire"]'));
        self::assertCount(0, $crawler->filter('[data-register-target="pill"][data-filter="alerts"]'));
        self::assertSelectorTextNotContains('.subnav', 'Fire module');
        self::assertSelectorTextNotContains('.subnav', 'With alerts');
        // The rows stop carrying the data keys those pills filtered on.
        self::assertCount(0, $crawler->filter('tr[data-fire]'));
        self::assertCount(0, $crawler->filter('tr[data-alerts]'));
        // The honest pills stay.
        self::assertSelectorExists('[data-register-target="pill"][data-filter="all"]');
        self::assertSelectorExists('[data-register-target="pill"][data-filter="live"]');
        self::assertSelectorExists('[data-register-target="pill"][data-filter="queued"]');
    }
}
