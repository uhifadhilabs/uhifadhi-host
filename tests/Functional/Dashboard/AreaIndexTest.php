<?php

declare(strict_types=1);

namespace Uhifadhi\Tests\Functional\Dashboard;

use Uhifadhi\Ingestion\Enum\DatasetKind;
use Uhifadhi\Ingestion\Factory\DatasetFactory;
use Uhifadhi\Spatial\Factory\AreaOfInterestFactory;
use Uhifadhi\Tests\Functional\AuthenticatedWebTestCase;

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
        DatasetFactory::createOne([
            'area' => $aoi, 'moduleSlug' => 'forest', 'key' => 'forest_loss_year',
            'kind' => DatasetKind::Series, 'columns' => ['year', 'ha', 'cumulative_ha'],
            'rows' => [[2010, 185.0, 185.0], [2013, 186.0, 371.0]],
        ]);
        AreaOfInterestFactory::createOne(['name' => 'Bare area']);

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Listed area');
        // Summed loss (185 + 186) shown as a bare figure under the loss column.
        self::assertSelectorTextContains('table', '371');
        self::assertSelectorTextContains('table', 'ha/yr');
        self::assertSelectorTextContains('table', 'Bare area');
        // A never-ingested area reads as queued (no live module yet).
        self::assertSelectorTextContains('table', 'queued');
        // stAreaKm2 renders a real geodesic size for the factory square.
        self::assertSelectorTextContains('table', 'km²');

        // Every filter pill renders with its live count (Listed = live, Bare = queued).
        self::assertSelectorTextContains('.subnav', 'All · 2');
        self::assertSelectorTextContains('.subnav', 'Live data · 1');
        self::assertSelectorTextContains('.subnav', 'Queued · 1');
        self::assertSelectorExists('[data-register-target="pill"][data-filter="alerts"]');
        // The controls are wired (search + sort), not decorative.
        self::assertSelectorExists('[data-register-target="search"][data-action*="register#search"]');
        self::assertSelectorExists('th[data-sort="loss"][data-action*="register#sortBy"]');
        // Every row shows a visible action + is clickable (not just a tinted title).
        self::assertSelectorTextContains('.open-btn', 'Open');
        self::assertSelectorExists('tr[data-action*="register#open"][data-href]');
    }
}
