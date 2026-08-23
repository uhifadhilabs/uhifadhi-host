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

use Uhifadhi\Factory\AreaOfInterestFactory;

/**
 * The per-area detail: map wiring scoped to the area, metrics, runs, and the
 * boundary map wired to this area only.
 */
final class AreaDetailTest extends AuthenticatedWebTestCase
{
    public function testTheDetailPageWiresTheMapToThisAreaOnly(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $aoi = AreaOfInterestFactory::createOne(['name' => 'Detail area']);

        $crawler = $client->request('GET', '/areas/'.$aoi->getUuidString());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="map"]');
        $wrapper = $crawler->filter('[data-controller="map"]');
        // The hub map carries THIS area's boundary; module layers ride on top
        // only when their bundles ship them (no layer URL is wired here).
        self::assertStringContainsString('MultiPolygon', (string) $wrapper->attr('data-map-boundary-value'));
        self::assertNull($wrapper->attr('data-map-forest-loss-url-value'));
        // The park-hub KPI plate carries the area size.
        self::assertSelectorTextContains('.kpi', 'km²');
        // The area tabs mark Overview active (Modules & Settings are the other tabs).
        self::assertSelectorTextContains('.atabs a.on', 'Overview');
    }

    public function testAreasAreAddressedByUuidNotSequentialId(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $aoi = AreaOfInterestFactory::createOne(['name' => 'Uuid only']);

        // The public URL uses the UUID and works.
        $client->request('GET', '/areas/'.$aoi->getUuidString());
        self::assertResponseIsSuccessful();

        // A sequential integer id must NOT resolve — the route only matches UUIDs.
        $client->request('GET', '/areas/'.$aoi->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testAMissingAreaIs404(): void
    {
        $client = static::createClient();
        $this->loginAs($client);

        // A well-formed but unknown UUID resolves to nothing.
        $client->request('GET', '/areas/'.\Symfony\Component\Uid\Uuid::v7()->toRfc4122());

        self::assertResponseStatusCodeSame(404);
    }
}
