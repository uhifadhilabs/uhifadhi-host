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

/**
 * Viewer-timezone time display, mount side.
 *
 * The host owns the localiser: a root-level `localtime` Stimulus controller mounted on the
 * document <body> that reads every `time[datetime]` and reformats it to the signed-in
 * viewer's OWN browser zone. What is pinned HERE is the server contract the controller
 * depends on — that it is actually mounted on the body of every authenticated page, and
 * that mounting it did not clobber the map module's basemap seam riding the same <body>.
 * The browser-zone conversion itself is JS and is verified in the browser.
 */
final class LocalTimeSurfaceTest extends AuthenticatedWebTestCase
{
    public function testTheBodyMountsTheLocaltimeControllerOnEveryPage(): void
    {
        $client = static::createClient();
        $this->loginAs($client);

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $controllers = (string) $crawler->filter('body')->attr('data-controller');
        // Stimulus reads data-controller as a space-separated token list.
        self::assertContains('localtime', preg_split('/\s+/', trim($controllers)) ?: []);
    }

    public function testMountingTheLocaliserLeavesTheBasemapSeamIntact(): void
    {
        $client = static::createClient();
        $this->loginAs($client);

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        // The map module publishes the satellite provider on the same <body> attribute set;
        // the localtime controller is added ALONGSIDE it, never in place of it.
        self::assertNotNull($crawler->filter('body')->attr('data-map-satellite'));
    }
}
