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

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Enum\TeamRoleEnum;
use Uhifadhi\Factory\AreaOfInterestFactory;
use Uhifadhi\Model\WidgetDom;
use Uhifadhi\Model\ZonesWidgets;
use Uhifadhi\Repository\ZoneRepository;
use Uhifadhi\Service\ZoneService;
use Uhifadhi\Tests\ZoneGeometry;

/**
 * The per-area ZONES surface: the tab that reaches it, the dashboard, the GeoJSON the map eats,
 * the registry's numbers, the import panel's verdicts, the manager-only writes, the empty state,
 * and the fact that one person's Ngorongoro layout is not their Pololeti layout.
 *
 * This proves the zones surface is WIRED — into the widget framework, into the Zone model layer
 * and into the area chrome. The framework's own algebra is {@see \Uhifadhi\Tests\Unit\WidgetServiceTest};
 * the zone invariant is {@see \Uhifadhi\Tests\Integration\ZoneServiceTest}.
 */
final class ZonesSurfaceTest extends AuthenticatedWebTestCase
{
    use ZoneGeometry;

    /**
     * The AOI the factory draws is the square 35.0..35.8 × −3.4..−2.9, so every zone below is a
     * strip INSIDE it — adjacent, sharing edges, never sharing interior.
     */
    private function zone(AreaOfInterest $area, string $name, float $minLon, float $maxLon): void
    {
        /** @var ZoneService $zones */
        $zones = self::getContainer()->get(ZoneService::class);
        $zones->create($area, $name, self::square($minLon, -3.4, $maxLon, -2.9));
    }

    // ── The tab ────────────────────────────────────────────────────────────────────────────

    public function testTheAreaTabRowCarriesZonesBetweenModulesAndSettings(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne();

        $crawler = $client->request('GET', '/areas/'.$area->getUuidString());

        self::assertResponseIsSuccessful();
        $tabs = $crawler->filter('.atabs a')->each(static fn ($n): string => trim($n->text()));
        self::assertSame(['Overview', 'Modules', 'Zones', 'Settings'], $tabs);
        self::assertSame(
            '/areas/'.$area->getUuidString().'/zones',
            $crawler->filter('.atabs a')->eq(2)->attr('href'),
        );
    }

    public function testTheZonesPageMarksItsOwnTabActive(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne();

        $crawler = $client->request('GET', '/areas/'.$area->getUuidString().'/zones');

        self::assertResponseIsSuccessful();
        self::assertSame('Zones', trim($crawler->filter('.atabs a.on')->text()));
    }

    // ── The dashboard ──────────────────────────────────────────────────────────────────────

    public function testTheDashboardRendersExactlyTheSplitManagerPresetByDefault(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne();
        $this->zone($area, 'Crater', 35.0, 35.2);

        $crawler = $client->request('GET', '/areas/'.$area->getUuidString().'/zones');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.w-grid');
        // The five ON widgets are exactly the design's `split` preset.
        foreach (['lens', 'picker', 'map', 'detail', 'import'] as $on) {
            self::assertCount(1, $crawler->filter('.w-grid > [data-widget-id="'.$on.'"]'), $on.' is on');
        }
        foreach (['kpis', 'rail', 'registry', 'gallery', 'table'] as $off) {
            self::assertCount(0, $crawler->filter('.w-grid > [data-widget-id="'.$off.'"]'), $off.' is off');
        }
    }

    public function testTheLensWidgetCarriesTheCopyContract(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne();
        $this->zone($area, 'Crater', 35.0, 35.2);

        $client->request('GET', '/areas/'.$area->getUuidString().'/zones');

        self::assertSelectorTextContains('[data-w="lens"]', 'A lens, not a fence.');
        self::assertSelectorTextContains('[data-w="lens"]', 'Zones never gate data');
    }

    // ── The GeoJSON the map eats ───────────────────────────────────────────────────────────

    public function testTheGeoJsonEndpointServesOneNamedColouredFeaturePerZone(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne();
        $this->zone($area, 'Crater', 35.0, 35.2);
        $this->zone($area, 'Olbalbal', 35.2, 35.5);

        $client->request('GET', '/areas/'.$area->getUuidString().'/zones.geojson');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/geo+json');
        /** @var array{type: string, features: list<array{type: string, properties: array<string, mixed>, geometry: array{type: string}}>} $document */
        $document = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('FeatureCollection', $document['type']);
        self::assertCount(2, $document['features']);
        // Name-ordered, exactly as ZoneRepository::zonesFor() returns them.
        self::assertSame(['Crater', 'Olbalbal'], array_column(array_column($document['features'], 'properties'), 'name'));
        $first = $document['features'][0];
        self::assertSame('Feature', $first['type']);
        self::assertSame('MultiPolygon', $first['geometry']['type']);
        self::assertArrayHasKey('uuid', $first['properties']);
        self::assertArrayHasKey('plate', $first['properties']);
        // The colour is served WITH the geometry so the legend and the polygon can never drift.
        self::assertIsString($first['properties']['color']);
        self::assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $first['properties']['color']);
        self::assertIsFloat($first['properties']['areaKm2']);
    }

    public function testTheGeoJsonOfAnUnzonedAreaIsAnEmptyFeatureCollectionNotAnError(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne();

        $client->request('GET', '/areas/'.$area->getUuidString().'/zones.geojson');

        self::assertResponseIsSuccessful();
        /** @var array{features: list<mixed>} $document */
        $document = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame([], $document['features']);
    }

    public function testTheGeoJsonOfOneAreaNeverCarriesAnothersZones(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $ngorongoro = AreaOfInterestFactory::createOne();
        $pololeti = AreaOfInterestFactory::createOne();
        $this->zone($ngorongoro, 'Crater', 35.0, 35.2);
        // Names are unique per AREA, not org-wide: the same name in both is legal and proves it.
        $this->zone($pololeti, 'Crater', 35.0, 35.2);
        $this->zone($pololeti, 'Olbalbal', 35.2, 35.5);

        $client->request('GET', '/areas/'.$ngorongoro->getUuidString().'/zones.geojson');

        /** @var array{features: list<mixed>} $document */
        $document = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertCount(1, $document['features']);
    }

    // ── The registry ───────────────────────────────────────────────────────────────────────

    public function testTheRegistryListsEveryZoneWithItsAreaAndItsShareOfTheAoi(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Manager);
        $area = AreaOfInterestFactory::createOne();
        $this->zone($area, 'Crater', 35.0, 35.2);
        $this->zone($area, 'Olbalbal', 35.2, 35.8);

        // The register is off in the default preset, so ask the library, which renders every
        // widget the surface ships at full size from the same partial the dashboard uses.
        //
        // Scoped to the CLONEABLE COPY. The library renders each widget once and re-uses that one
        // render in three places (the <template>, the canvas, the picker's stage), so an unscoped
        // selector would count the same two zones twice over. The <template> is the copy that is
        // always present whatever the active preset holds.
        $crawler = $client->request('GET', '/areas/'.$area->getUuidString().'/zones/widgets');

        self::assertResponseIsSuccessful();
        $registry = '['.WidgetDom::TEMPLATE.'="registry"] ';
        $rows = $crawler->filter($registry.'[data-w="registry"] tbody tr[data-zone-uuid]');
        self::assertCount(2, $rows);
        self::assertSame(['Crater', 'Olbalbal'], $rows->each(
            static fn ($n): string => trim($n->filter('[data-zone-name]')->text()),
        ));
        // km² is the repository's geodesic calc, not a guess: the strips are a quarter and three
        // quarters of the AOI, so a positive area and a share are printed for each.
        $numbers = $rows->eq(0)->filter('td.num')
            ->each(static fn ($n): string => trim($n->text()));
        self::assertMatchesRegularExpression('/^[\d,]+\.\d$/', $numbers[0], 'km²');
        self::assertMatchesRegularExpression('/^\d+\.\d%$/', $numbers[1], 'share of the AOI');
        self::assertGreaterThan(0.0, (float) str_replace(',', '', $numbers[0]));
    }

    // ── Import ─────────────────────────────────────────────────────────────────────────────

    public function testImportingAGeoJsonFileLandsTheWholeSetAtOnce(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Manager);
        $area = AreaOfInterestFactory::createOne();

        $this->postImport($client, $area, [
            self::squareFeature('Crater', 35.0, -3.4, 35.2, -2.9),
            self::squareFeature('Olbalbal', 35.2, -3.4, 35.5, -2.9),
        ]);

        self::assertResponseRedirects('/areas/'.$area->getUuidString().'/zones');
        $crawler = $client->followRedirect();
        self::assertStringContainsString('2 zones imported', $crawler->filter('.zflash')->text());
        self::assertCount(2, $crawler->filter('[data-w="picker"] [data-zone-uuid]'));
    }

    public function testARejectedImportSurfacesTheServicesOwnMessageAndChangesNothing(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Manager);
        $area = AreaOfInterestFactory::createOne();
        $this->zone($area, 'Crater', 35.0, 35.2);

        // Two overlapping features: the second shares interior with the first.
        $this->postImport($client, $area, [
            self::squareFeature('Serengeti Gate', 35.3, -3.4, 35.6, -2.9),
            self::squareFeature('Naiyobi', 35.4, -3.4, 35.7, -2.9),
        ]);

        self::assertResponseRedirects('/areas/'.$area->getUuidString().'/zones');
        $crawler = $client->followRedirect();
        // VERBATIM: the overlap-naming message the model layer wrote, both zones named.
        self::assertStringContainsString(
            'overlaps zone "Serengeti Gate" — zones of one area may touch along an edge or leave gaps, but never share interior.',
            $crawler->filter('.zflash')->text(),
        );
        // All-or-nothing: the area still holds exactly the one zone it started with.
        self::assertCount(1, $crawler->filter('[data-w="picker"] [data-zone-uuid]'));
    }

    public function testAnImportThatNamesNothingSurfacesTheParsersOwnMessage(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Manager);
        $area = AreaOfInterestFactory::createOne();

        $feature = self::squareFeature('ignored', 35.0, -3.4, 35.2, -2.9);
        $feature['properties'] = [];
        $this->postImport($client, $area, [$feature]);

        $crawler = $client->followRedirect();
        self::assertStringContainsString(
            'Feature #1 has no "name" property — every zone must be named.',
            $crawler->filter('.zflash')->text(),
        );
    }

    // ── Manager-only writes ────────────────────────────────────────────────────────────────

    public function testRenameAndDeleteAreOfferedToAManager(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Manager);
        $area = AreaOfInterestFactory::createOne();
        $this->zone($area, 'Crater', 35.0, 35.2);

        $crawler = $client->request('GET', '/areas/'.$area->getUuidString().'/zones');

        self::assertCount(1, $crawler->filter('[data-w="detail"] form[data-zone-rename]'));
        self::assertCount(1, $crawler->filter('[data-w="detail"] form[data-zone-delete]'));
        // The consequence, in plain English, beside the button that causes it.
        self::assertStringContainsString('stay exactly where they are', $crawler->filter('[data-w="detail"] .dangerbar')->text());
        // And it asks first, through the platform's confirm modal — never the browser's dialog.
        self::assertStringContainsString(
            'confirm-modal',
            (string) $crawler->filter('[data-w="detail"] form[data-zone-delete] button')->attr('data-controller'),
        );
    }

    public function testAReaderSeesTheZonesAndNeitherForm(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);
        $area = AreaOfInterestFactory::createOne();
        $this->zone($area, 'Crater', 35.0, 35.2);

        $crawler = $client->request('GET', '/areas/'.$area->getUuidString().'/zones');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-w="picker"] [data-zone-uuid]'), 'staff still read the zones');
        self::assertCount(0, $crawler->filter('[data-w="detail"] form[data-zone-rename]'));
        self::assertCount(0, $crawler->filter('[data-w="detail"] form[data-zone-delete]'));
    }

    public function testStaffMayNotRenameAZoneEvenByPostingDirectly(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);
        $area = AreaOfInterestFactory::createOne();
        $this->zone($area, 'Crater', 35.0, 35.2);
        /** @var ZoneRepository $repository */
        $repository = self::getContainer()->get(ZoneRepository::class);
        $uuid = (string) $repository->findOneForName($area, 'Crater')?->getUuidString();

        $client->request('POST', '/areas/'.$area->getUuidString().'/zones/'.$uuid.'/rename', ['name' => 'Ngorongoro']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testStaffMayNotImportZones(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);
        $area = AreaOfInterestFactory::createOne();

        $client->request('POST', '/areas/'.$area->getUuidString().'/zones/import');

        self::assertResponseStatusCodeSame(403);
    }

    // ── Presence ───────────────────────────────────────────────────────────────────────────

    public function testAnAreaWithNoZonesGetsOnePlateAndNoDashboard(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Manager);
        $area = AreaOfInterestFactory::createOne();

        $crawler = $client->request('GET', '/areas/'.$area->getUuidString().'/zones');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.zempty'));
        self::assertCount(0, $crawler->filter('.w-grid'), 'no half-drawn dashboard of empty plates');
        self::assertSelectorTextContains('.zempty', 'This area has no zones yet');
        self::assertSelectorTextContains('.zempty', 'a lens, not a fence');
        // The one way in is offered; the flow that comes later is rendered and inert.
        self::assertCount(1, $crawler->filter('.zempty form[data-zone-import]'));
        self::assertCount(1, $crawler->filter('.zempty .btn.soon[disabled]'));
        self::assertStringContainsString('app:zone:import', $crawler->filter('.zempty')->text());
    }

    public function testTheEmptyStateGivesAReaderNoImportForm(): void
    {
        $client = static::createClient();
        $this->loginAs($client, TeamRoleEnum::Staff);
        $area = AreaOfInterestFactory::createOne();

        $crawler = $client->request('GET', '/areas/'.$area->getUuidString().'/zones');

        self::assertCount(1, $crawler->filter('.zempty'));
        self::assertCount(0, $crawler->filter('.zempty form[data-zone-import]'));
    }

    public function testAnAreaWithZonesShowsNoEmptyState(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne();
        $this->zone($area, 'Crater', 35.0, 35.2);

        $crawler = $client->request('GET', '/areas/'.$area->getUuidString().'/zones');

        self::assertCount(0, $crawler->filter('.zempty'));
        self::assertCount(1, $crawler->filter('.w-grid'));
    }

    // ── The library, and per-area preferences ──────────────────────────────────────────────

    public function testTheLibraryDrawsTheFiveDirectionsAndEveryWidget(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $area = AreaOfInterestFactory::createOne();
        $this->zone($area, 'Crater', 35.0, 35.2);

        $crawler = $client->request('GET', '/areas/'.$area->getUuidString().'/zones/widgets');

        self::assertResponseIsSuccessful();
        // THE SHARED PRESET COMPONENT, over this surface's catalogue: the five directions the zone
        // manager was drawn in are the strip's cards, each adoptable whole.
        self::assertSame(
            ['Map hero', 'Registry first', 'Split manager', 'Card gallery', 'Inside Settings'],
            $crawler->filter('[data-presets="designs"] .w-presetname')->each(
                static fn ($n): string => trim($n->text()),
            ),
        );
        // Split manager is the design this surface ships, so it is the one card wearing Active —
        // for a person who has never chosen, exactly as ZonesWidgets::DEFAULT_PRESET says.
        self::assertCount(1, $crawler->filter('.w-preset-active'));
        self::assertSame('Split manager', trim($crawler->filter('.w-preset-active .w-presetname')->text()));
        // Every widget the surface ships is rendered once, as its own real partial, ready to
        // clone — that is what makes the preview and the picker cost no round trip.
        self::assertCount(10, $crawler->filter('['.WidgetDom::TEMPLATE.']'));
        // THE CANVAS IS THE DASHBOARD: exactly the active design's five widgets, nothing greyed.
        self::assertCount(5, $crawler->filter('.w-canvas > .w-card'));

        // Every framework URL the script drives is this AREA's, the preset routes included.
        $root = $crawler->filter('['.WidgetDom::ROOT.']');
        $mine = '/areas/'.$area->getUuidString().'/zones/widgets';
        self::assertSame($mine.'/save', $root->attr(WidgetDom::SAVE_URL));
        self::assertSame($mine.'/reset', $root->attr(WidgetDom::RESET_URL));
        self::assertSame($mine.'/preset/'.WidgetDom::ID_PLACEHOLDER, $root->attr(WidgetDom::PRESET_URL));
        self::assertSame($mine.'/preset/'.WidgetDom::ID_PLACEHOLDER.'/copy', $root->attr(WidgetDom::PRESET_COPY_URL));
        self::assertSame($mine.'/presets', $root->attr(WidgetDom::PRESETS_URL));
        self::assertSame($mine.'/presets/'.WidgetDom::ID_PLACEHOLDER.'/apply', $root->attr(WidgetDom::PRESET_APPLY_URL));
    }

    public function testAShippedZoneDesignCannotBeEditedButACopyOfItCanAndItIsThisAreasAlone(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $ngorongoro = AreaOfInterestFactory::createOne();
        $pololeti = AreaOfInterestFactory::createOne();
        $this->zone($ngorongoro, 'Crater', 35.0, 35.2);
        $this->zone($pololeti, 'Crater', 35.0, 35.2);

        $here = '/areas/'.$ngorongoro->getUuidString().'/zones/widgets';
        $crawler = $client->request('GET', $here);
        $token = (string) $crawler->filter('['.WidgetDom::ROOT.']')->attr(WidgetDom::CSRF_TOKEN);

        // A shipped design is immutable, so editing the canvas while one is active is refused.
        $layout = json_encode([
            'order' => ['registry'],
            'widgets' => ['registry' => ['on' => true, 'cols' => 12]],
        ], \JSON_THROW_ON_ERROR);
        $client->request('POST', $here.'/save', server: [
            'HTTP_'.str_replace('-', '_', strtoupper(WidgetDom::CSRF_HEADER)) => $token,
            'CONTENT_TYPE' => 'application/json',
        ], content: $layout);
        self::assertResponseStatusCodeSame(422);

        // The one door into an editable layout — and it becomes active for THIS area.
        $client->request('POST', $here.'/preset/split/copy', ['_token' => $token, 'name' => 'My zones view']);
        self::assertResponseRedirects($here);

        $client->request('POST', $here.'/save', server: [
            'HTTP_'.str_replace('-', '_', strtoupper(WidgetDom::CSRF_HEADER)) => $token,
            'CONTENT_TYPE' => 'application/json',
        ], content: $layout);
        self::assertResponseStatusCodeSame(204);

        $changed = $client->request('GET', '/areas/'.$ngorongoro->getUuidString().'/zones');
        self::assertCount(1, $changed->filter('.w-grid > [data-widget-id="registry"]'));

        // The other area never left the shipped design: presets are per user AND per area.
        $other = $client->request('GET', '/areas/'.$pololeti->getUuidString().'/zones/widgets');
        self::assertSame('Split manager', trim($other->filter('.w-preset-active .w-presetname')->text()));
        self::assertCount(0, $other->filter('[data-presets="mine"] [data-preset-kind="mine"]'));
    }

    public function testALayoutSavedForOneAreaLeavesTheOtherAreaAlone(): void
    {
        $client = static::createClient();
        $this->loginAs($client);
        $ngorongoro = AreaOfInterestFactory::createOne();
        $pololeti = AreaOfInterestFactory::createOne();
        $this->zone($ngorongoro, 'Crater', 35.0, 35.2);
        $this->zone($pololeti, 'Crater', 35.0, 35.2);

        // Adopt "Registry first" — a wholly different set of widgets — for Ngorongoro only.
        $crawler = $client->request('GET', '/areas/'.$ngorongoro->getUuidString().'/zones/widgets');
        $token = (string) $crawler->filter('['.WidgetDom::ROOT.']')->attr(WidgetDom::CSRF_TOKEN);
        $client->request(
            'POST',
            '/areas/'.$ngorongoro->getUuidString().'/zones/widgets/preset/registry',
            ['_token' => $token],
        );
        self::assertResponseRedirects('/areas/'.$ngorongoro->getUuidString().'/zones');

        $changed = $client->request('GET', '/areas/'.$ngorongoro->getUuidString().'/zones');
        self::assertCount(1, $changed->filter('.w-grid > [data-widget-id="registry"]'));
        self::assertCount(0, $changed->filter('.w-grid > [data-widget-id="picker"]'));

        // The other area is untouched: same person, same surface, its own row.
        $other = $client->request('GET', '/areas/'.$pololeti->getUuidString().'/zones');
        self::assertCount(0, $other->filter('.w-grid > [data-widget-id="registry"]'));
        self::assertCount(1, $other->filter('.w-grid > [data-widget-id="picker"]'));
    }

    public function testTheSurfaceIsTheOneTheCatalogueNames(): void
    {
        self::assertSame('zones', ZonesWidgets::SURFACE);
    }

    /**
     * @param list<array<string, mixed>> $features
     */
    private function postImport(KernelBrowser $client, AreaOfInterest $area, array $features): void
    {
        $file = tempnam(sys_get_temp_dir(), 'zones').'.geojson';
        file_put_contents($file, json_encode(self::featureCollection($features), \JSON_THROW_ON_ERROR));

        // The token comes off the rendered form, like any other write on this site.
        $crawler = $client->request('GET', '/areas/'.$area->getUuidString().'/zones');
        $token = (string) $crawler->filter('form[data-zone-import] input[name="_token"]')->attr('value');

        $client->request(
            'POST',
            '/areas/'.$area->getUuidString().'/zones/import',
            ['_token' => $token],
            ['zones' => new UploadedFile($file, 'zones.geojson', 'application/geo+json', test: true)],
        );
    }
}
