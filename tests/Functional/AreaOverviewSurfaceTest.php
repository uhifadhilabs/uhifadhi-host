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

use Symfony\Component\Uid\Uuid;
use Uhifadhi\Factory\AreaOfInterestFactory;
use Uhifadhi\Model\WidgetDom;

/**
 * THE AREA OVERVIEW AS A WIDGET SURFACE — /areas/{uuid}.
 *
 * What this proves is the part a unit test cannot: that the page is WIRED — the
 * area header and its two actions, the tab strip, the composed grid, the widget
 * library with its preset strip, and the honest absent states the host draws
 * where this app records nothing.
 *
 * The composition algebra is {@see \Uhifadhi\Tests\Unit\Overview\AreaOverviewCatalogueTest};
 * the merge of contributed parts is
 * {@see \Uhifadhi\Tests\Unit\Overview\AreaOverviewComposerTest}. A module's own
 * widgets are that module's suite — this one runs with no module installed,
 * which is the case the host has to survive on its own.
 */
final class AreaOverviewSurfaceTest extends AuthenticatedWebTestCase
{
    public function testTheAreaHeaderCarriesTheBreadcrumbTheTwoActionsAndTheTabs(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne(['name' => 'Detail area']);

        $crawler = $client->request('GET', '/areas/'.$area->getUuidString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1.pg', 'Detail area');
        self::assertSelectorTextContains('.crumb', 'uhifadhi');
        self::assertSelectorTextContains('.crumb', 'detail area');
        // The library is where a direction is adopted; the same control, in the
        // same place, on every widget surface.
        self::assertSame(
            '/areas/'.$area->getUuidString().'/overview/widgets',
            $crawler->filter('.pgact a.w-act')->attr('href'),
        );
        self::assertSelectorTextContains('.pgact a.w-act', 'Widgets');
        self::assertSelectorTextContains('.pgact a.cta', 'Add module');
        self::assertSelectorTextContains('.atabs a.on', 'Overview');
    }

    public function testThePageIsAComposedGridAndTheHostSurvivesWithNoModuleInstalled(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne();

        $crawler = $client->request('GET', '/areas/'.$area->getUuidString());

        self::assertResponseIsSuccessful();
        // The shipped composition: identity, the strip, attention, the map,
        // presence, the modules card — the host's own six, in that order.
        self::assertSame(
            ['ident', 'nowbar', 'attention', 'map', 'presence', 'modules'],
            $crawler->filter('.w-grid > .w-cell[data-widget-id]')->each(
                static fn ($cell): string => (string) $cell->attr('data-widget-id'),
            ),
        );
        // The identity facts are a band, not four plates: that decision is the
        // reason this page was redrawn.
        self::assertSelectorExists('.ao-ident');
        self::assertSelectorTextContains('.ao-ident', 'km²');
        // Every widget wears its contributor.
        self::assertSelectorExists('.ao-by.host');
    }

    public function testTheStripAndTheAttentionListSayNothingRatherThanZero(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne();

        $client->request('GET', '/areas/'.$area->getUuidString());

        // ABSENT IS NOT ZERO. With no module installed there is no tile to lay
        // out and nothing is asking for anyone — and both widgets say so in
        // words rather than drawing an empty row or a 0.
        self::assertSelectorNotExists('.dp-kstrip');
        self::assertSelectorTextContains('[data-widget-id="nowbar"]', 'Nothing is reporting into this strip');
        self::assertSelectorTextContains('[data-widget-id="attention"]', 'Nothing is asking for you');
        self::assertSelectorNotExists('.ao-att');
    }

    public function testThePlateIsTheHostsAndEveryLayerOnItShipsALegendEntry(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne();

        $crawler = $client->request('GET', '/areas/'.$area->getUuidString());

        // One plate, driven by the host's own controller, carrying the two
        // layers the host itself owns — and the legend IS the switch.
        self::assertSelectorExists('[data-controller="overview-map"]');
        self::assertSelectorExists('.ao-legend');
        $entries = $crawler->filter('.ao-legend .lay')->each(
            static fn ($lay): array => [(string) $lay->attr('data-layer-id'), $lay->attr('class')],
        );
        self::assertSame(['host.boundary', 'host.zones'], array_column($entries, 0));
        // WHAT IS ON BY DEFAULT IS OPERATIONAL. A zone is a lens, so it is one
        // click away rather than in the way at 07:00.
        self::assertStringNotContainsString('off', (string) $entries[0][1]);
        self::assertStringContainsString('off', (string) $entries[1][1]);
        self::assertStringContainsString('MultiPolygon', (string) $crawler->filter('[data-controller="overview-map"]')->attr('data-overview-map-layers-value'));
    }

    public function testTheLibraryOffersTheShippedCompositionAndTheFiveDirections(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne();

        $crawler = $client->request('GET', '/areas/'.$area->getUuidString().'/overview/widgets');

        self::assertResponseIsSuccessful();
        // ONE ACTIVE PRESET, and a fresh person is on the composition the host
        // ships — which is none of the five directions, on purpose.
        self::assertSelectorTextContains('.w-presetflag-active', 'Active');
        $labels = $crawler->filter('.w-presetname')->each(static fn ($n): string => trim($n->text()));
        self::assertContains('The area overview', $labels);
        foreach (['Pulse first', 'Map as ground', 'Module columns', 'Duty board', 'Attention queue'] as $direction) {
            self::assertContains($direction, $labels, $direction.' ships as a preset, not as a parallel page.');
        }
        // THE HEADED SECTIONS ARE CONTRIBUTORS on this surface, and the page
        // says so rather than leaving it to the code.
        self::assertSelectorTextContains('.w-galnote', 'a contributor, not a direction');
        // …and the picker's rail is those sections: the host, then each
        // installed module, then the catalogue's uninstalled ones last.
        self::assertSame(
            ['All widgets', 'The area itself', 'Not installed in this area'],
            $crawler->filter('[data-pick-tab]')->each(
                static fn ($tab): string => trim($tab->filter('span')->count() ? substr($tab->text(), 0, -\strlen($tab->filter('span')->text())) : $tab->text()),
            ),
        );
    }

    public function testACustomArrangementIsSavedPerPersonAndPerArea(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $one = AreaOfInterestFactory::createOne(['name' => 'First area']);
        $two = AreaOfInterestFactory::createOne(['name' => 'Second area']);

        $crawler = $client->request('GET', '/areas/'.$one->getUuidString().'/overview/widgets');
        $token = (string) $crawler->filter('['.WidgetDom::ROOT.']')->attr(WidgetDom::CSRF_TOKEN);

        // Adopt a direction on the first area only.
        $client->request(
            'POST',
            '/areas/'.$one->getUuidString().'/overview/widgets/preset/e',
            server: ['HTTP_'.str_replace('-', '_', strtoupper(WidgetDom::CSRF_HEADER)) => $token],
        );
        self::assertResponseRedirects('/areas/'.$one->getUuidString());

        $client->request('GET', '/areas/'.$one->getUuidString());
        self::assertSame(
            // "Attention queue", trimmed to what an area with no module has: the
            // worklist, the map, and the identity facts sunk to a footer band.
            ['attention', 'map', 'ident'],
            $client->getCrawler()->filter('.w-grid > .w-cell[data-widget-id]')->each(
                static fn ($cell): string => (string) $cell->attr('data-widget-id'),
            ),
        );

        // The other area is untouched: preferences are per person AND per area.
        $client->request('GET', '/areas/'.$two->getUuidString());
        self::assertSame(
            ['ident', 'nowbar', 'attention', 'map', 'presence', 'modules'],
            $client->getCrawler()->filter('.w-grid > .w-cell[data-widget-id]')->each(
                static fn ($cell): string => (string) $cell->attr('data-widget-id'),
            ),
        );
    }

    public function testTheWidgetsThisAppHasNoDataForSayWhatIsMissingRatherThanShowingNumbers(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne();

        $client->request('GET', '/areas/'.$area->getUuidString());

        // "Stations & who is on" needs a roster, a shift and a handset check-in.
        // The app records people, positions and departments — and none of those
        // three. A staff directory pretending to be a duty roster would be worse
        // than saying so.
        self::assertSelectorTextContains('[data-widget-id="presence"]', 'this app records neither yet');
        self::assertSelectorNotExists('.ao-pres');
    }

    public function testTheSurfaceHasExactlyOnePollingEndpointAndItRefreshesOnlyWhatWearsTheLiveDot(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne();

        $client->request('GET', '/areas/'.$area->getUuidString().'/overview/now');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        /** @var array{strip: string, layers: array<string, mixed>} $payload */
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        // The strip comes back as its own markup, so the refresh cannot draw a
        // tile differently from the render.
        self::assertArrayHasKey('strip', $payload);
        // ONLY WHAT POLLS. The host's two layers are the boundary and the zones,
        // and neither of them moves — refreshing them every ten seconds would be
        // a load test rather than a live map.
        self::assertSame([], $payload['layers']);

        // And there is exactly one such endpoint on the surface: an overview with
        // six independent pollers is a load test too.
        self::bootKernel();
        /** @var \Symfony\Component\Routing\RouterInterface $router */
        $router = static::getContainer()->get('router');
        $polling = [];
        foreach ($router->getRouteCollection() as $route) {
            if (str_contains($route->getPath(), '/overview/') && str_ends_with($route->getPath(), '/now')) {
                $polling[] = $route->getPath();
            }
        }
        self::assertSame(['/areas/{uuid}/overview/now'], $polling);
    }

    public function testAreasAreAddressedByUuidNotSequentialId(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne(['name' => 'Uuid only']);

        $client->request('GET', '/areas/'.$area->getUuidString());
        self::assertResponseIsSuccessful();

        // A sequential integer id must NOT resolve — the route only matches UUIDs.
        $client->request('GET', '/areas/'.$area->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testAMissingAreaIs404(): void
    {
        $client = static::createClient();
        $this->loginAs($client);

        $client->request('GET', '/areas/'.Uuid::v7()->toRfc4122());

        self::assertResponseStatusCodeSame(404);
    }
}
