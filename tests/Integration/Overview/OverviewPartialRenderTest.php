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

namespace Uhifadhi\Tests\Integration\Overview;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\KernelInterface;
use Twig\Environment;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\Module;
use Uhifadhi\Enum\ModuleCategory;
use Uhifadhi\Enum\ModuleStatus;
use Uhifadhi\Factory\AreaOfInterestFactory;
use Uhifadhi\Overview\AttentionItem;
use Uhifadhi\Overview\AttentionSeverity;
use Uhifadhi\Overview\MapLayer;
use Uhifadhi\Overview\NowTile;
use Uhifadhi\Overview\PulseEvent;
use Uhifadhi\Repository\AreaModuleRepository;
use Uhifadhi\Service\AreaOverviewCatalogue;
use Uhifadhi\Service\AreaOverviewComposer;
use Uhifadhi\Service\AreaOverviewContext;
use Uhifadhi\Service\OverviewCopy;
use Zenstruck\Foundry\Test\Factories;

/**
 * EVERY WIDGET ON THE AREA OVERVIEW, DRAWN — the test that exists because two
 * production 500s escaped a green suite.
 *
 * The composition was tested to death: which widgets a catalogue holds, how the
 * parts merge, what a preset trims to. NONE OF IT DRAWS A TEMPLATE, and both
 * escapes were in a template — the dock printed a GeoJSON coordinate ARRAY into
 * `data-lon` because it assumed every layer carries Points, and a track's
 * LineString (and then a coverage MultiPolygon) is not a Point. A test that
 * renders the partial with a track in it fails on the first one.
 *
 * WHAT MAKES THIS STRUCTURAL RATHER THAN A LIST:
 *
 * - The partials are ENUMERATED FROM THE SEAM ({@see AreaOverviewCatalogue::partialsFor}),
 *   over an area with every installed module switched on, so a widget added by
 *   the host or by a module bundle nobody has written yet is covered the day it
 *   is declared. {@see testEveryHostWidgetPartialOnDiskIsReachedThroughTheSeam}
 *   closes the other direction.
 * - The context is the PAGE'S OWN ({@see AreaOverviewContext}), not a hand-built
 *   map, so a partial cannot pass here by reading a key production never sets.
 * - Every partial is drawn TWICE: with nothing to say, and with something to
 *   say. The honest-absent states are first-class on this surface — half the
 *   host's widgets exist mainly to say what the app does not record — so a
 *   template that only survives when the data is there is only half-shipped.
 * - The map-bearing plates see FEATURES OF ALL FIVE GEOJSON SHAPES. Points,
 *   tracks, multi-tracks, polygons and multi-polygons all reach this surface
 *   from real modules today, and the dock's regression is pinned directly in
 *   {@see testTheDockAnswersWithAScalarPointForEveryGeoJsonShape}.
 *
 * A PHP warning or notice raised while drawing is a failure here, not a line in
 * a log: the handler below turns one into an exception naming the template.
 *
 * A MODULE'S OWN PLATES, WITH THE MODULE'S OWN DATA, are that module's suite
 * (patrol-module's PatrolOverviewTemplatesTest, incident-module's
 * OverviewPartialsTest). What this test adds for them is the case only the host
 * can stage: drawn inside the host's page, through the host's context, in an
 * area where the module records nothing yet.
 */
final class OverviewPartialRenderTest extends KernelTestCase
{
    use Factories;

    /** Where the host's own widget partials live, as Twig names them. */
    private const string HOST_TEMPLATE_DIR = 'area/overview';

    private AreaOfInterest $area;

    /** @var list<string> */
    private array $installed;

    /** @var array<string, string> widget id => partial */
    private array $partials;

    /** @var array<string, mixed> */
    private array $context;

    protected function setUp(): void
    {
        self::bootKernel();

        // The area exists before the catalogue is seeded, so the seed's own
        // backfill installs every module the deployment ships — the same path a
        // real deployment takes, which is what makes a future module bundle
        // covered by this test without anybody editing it.
        $this->area = AreaOfInterestFactory::createOne([
            'name' => 'Ngorongoro Conservation Area',
            'iucnCategory' => 'VI',
            'establishedYear' => 1959,
        ]);

        $this->seedAndInstallEveryModule();

        $this->installed = $this->installedSlugs();
        $this->partials = $this->service(AreaOverviewCatalogue::class)->partialsFor($this->installed);
        $this->context = $this->service(AreaOverviewContext::class)->for($this->area, $this->installed, self::now());
    }

    /**
     * NOTHING TO SAY IS A STATE, and on this surface it is the common one: a
     * fresh area has no patrol out, no incident open and no move in the pulse.
     * Every plate has to draw that.
     */
    public function testEveryWidgetPartialDrawsItsHonestAbsentState(): void
    {
        self::assertNotSame([], $this->partials);

        foreach ($this->partials as $widgetId => $partial) {
            $html = $this->render($partial, $this->context);
            self::assertNotSame('', trim($html), $widgetId.' draws nothing at all.');
        }
    }

    /**
     * The same partials with a morning in them: contributed tiles, an attention
     * list at all three severities, a pulse over two days, and a plate carrying
     * every GeoJSON shape a module can put on it.
     */
    public function testEveryWidgetPartialDrawsAFullMorning(): void
    {
        $context = $this->populated();

        foreach ($this->partials as $widgetId => $partial) {
            $html = $this->render($partial, $context);
            self::assertNotSame('', trim($html), $widgetId.' draws nothing at all.');
        }

        // The two host plates that lay out contributed parts really did lay them
        // out — otherwise the loop above would pass on a template that renders
        // its empty branch whatever it is handed.
        $strip = $this->render($this->partials['nowbar'], $context);
        self::assertStringContainsString('Out right now', $strip);
        self::assertStringContainsString('endulen · naabi · nainokanoka', $strip);
        self::assertStringContainsString('Snare line at the forest edge', $this->render($this->partials['attention'], $context));
    }

    /**
     * THE DOCK REGRESSION, PINNED. A row carries its own point so the plate can
     * say whether it is still in view, and a point is TWO NUMBERS — whatever the
     * feature's geometry is. A LineString printed whole gave `data-lon="Array"`
     * and a PHP warning; a MultiPolygon gave the same thing one level deeper.
     */
    public function testTheDockAnswersWithAScalarPointForEveryGeoJsonShape(): void
    {
        $layers = $this->layersOfEveryShape();
        $html = $this->render($this->partials['mapdock'], $this->withLayers($layers));

        $rows = new Crawler($html)->filter('.i-listbody a.i-hit');

        $drawn = 0;
        foreach ($layers as $layer) {
            $features = $layer->features['features'];
            if ($layer->on && \is_array($features)) {
                $drawn += \count($features);
            }
        }
        self::assertSame($drawn, $rows->count(), 'The dock does not row every drawn feature.');
        self::assertGreaterThanOrEqual(5, $drawn);

        // A ROW EITHER CARRIES A POINT OR CARRIES NONE. What it may never carry
        // is a coordinate array: that is what shipped, twice, and what the
        // browser read as `data-lon="Array"`.
        $unlocated = 0;
        foreach ($rows as $row) {
            \assert($row instanceof \DOMElement);
            $lon = $row->getAttribute('data-lon');
            $lat = $row->getAttribute('data-lat');
            $ref = new Crawler($row)->filter('.id')->text();

            if ('' === $lon && '' === $lat) {
                ++$unlocated;
                continue;
            }
            foreach (['data-lon' => $lon, 'data-lat' => $lat] as $attribute => $value) {
                self::assertTrue(
                    is_numeric($value),
                    \sprintf('%s of %s is "%s" — a coordinate array reached the markup instead of one number.', $attribute, $ref, $value),
                );
            }
        }

        // Exactly the two features that have no point of their own: the
        // unlocated one and the GeometryCollection.
        self::assertSame(2, $unlocated, 'A feature with a geometry was docked without its point.');

        // The dock's own count reads the same features the rows do.
        self::assertStringContainsString($drawn.' drawn', $html);
    }

    /**
     * The map plate and its legend take the same five shapes, and the legend
     * still carries the layer that has nothing to draw — a legend that appears
     * and disappears with the data is a legend nobody can rely on.
     */
    public function testTheMapPlateAndItsLegendTakeEveryGeoJsonShape(): void
    {
        $layers = $this->layersOfEveryShape();
        $html = $this->render($this->partials['map'], $this->withLayers($layers));

        $crawler = new Crawler($html);
        self::assertCount(\count($layers), $crawler->filter('.ao-legend a.lay'));

        /** @var list<array{id: string, style: string, features: array{type: string}}> $adopted */
        $adopted = json_decode(
            (string) $crawler->filter('[data-controller="overview-map"]')->attr('data-overview-map-layers-value'),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        self::assertCount(\count($layers), $adopted);
        foreach ($adopted as $layer) {
            self::assertSame('FeatureCollection', $layer['features']['type']);
        }
    }

    /**
     * AO·08 DRAWS THE SEAM, WHICH MEANS DRAWING BOTH SIDES OF IT. The design's
     * table lists what this area has AND, muted underneath, what the catalogue
     * holds that it has not — "Permits · nothing — not installed in this area ·
     * —". A card that only ever lists the installed ones says "8 in the
     * catalogue" over two rows and leaves a person to wonder what the other six
     * are; the whole point of a seam card is that the absence is visible.
     *
     * Nothing is invented for such a row: the catalogue's name, and the fact
     * that it contributed nothing here.
     */
    public function testTheModulesCardRowsACatalogueModuleThisAreaDoesNotHave(): void
    {
        $em = $this->service(EntityManagerInterface::class);
        $em->persist(new Module()
            ->setSlug('permits')
            ->setName('Permits')
            ->setCategory(ModuleCategory::Pressure)
            ->setStatus(ModuleStatus::Template)
            ->setDataSource('Permit register')
            ->setPosition(90));
        $em->flush();

        // The area's own modules are unchanged: nothing was installed, so the
        // catalogue simply grew by one that this area does not have.
        $context = $this->service(AreaOverviewContext::class)->for($this->area, $this->installed, self::now());
        $html = $this->render($this->partials['modules'], $context);

        $rows = new Crawler($html)->filter('table.tbl tr');
        self::assertSame(
            \count($this->installed) + 2, // the header row, the installed ones, and Permits
            $rows->count(),
            'The card does not row the catalogue module this area has not installed.',
        );

        $absent = $rows->last();
        self::assertStringContainsString('Permits', $absent->text());
        self::assertStringContainsString('nothing — not installed in this area', $absent->text());

        // The header's count and the rows now describe the same catalogue.
        self::assertStringContainsString(\count($this->installed) + 1 .' in the catalogue', $html);
    }

    /**
     * THE HOST END OF THE COPY SEAM, THROUGH A REAL CONTAINER: the catalogue
     * does not write the plate's picker line or the map-led direction's thesis
     * — it asks {@see OverviewCopy} for them, per area.
     *
     * The assertion is an IDENTITY rather than a literal on purpose. What can
     * regress here is the catalogue quietly going back to a hard-coded string;
     * whether the composed sentence is the design's wording is decided by the
     * fragments, and that is pinned character by character in
     * {@see \Uhifadhi\Tests\Unit\Overview\OverviewCopyTest} (a literal here
     * would instead pin which module bundles this checkout's vendor happens to
     * hold).
     */
    public function testThePlatesCopyComesFromTheSeamAndNotFromTheHost(): void
    {
        $copy = $this->service(OverviewCopy::class);
        $catalog = $this->service(AreaOverviewCatalogue::class)->for($this->installed);

        self::assertSame($copy->mapNote($this->installed), $catalog->get('map')->note);
        self::assertSame($copy->mapGroundThesis($this->installed), $catalog->preset('b')?->description);

        // AND IT IS PER AREA. An area with nothing installed gets the shorter,
        // truer sentence — the host string it replaced said "today's tracks and
        // open incidents" on every area in the deployment.
        $bare = $this->service(AreaOverviewCatalogue::class)->for([]);
        self::assertSame(
            'Boundary and stations. Scientific layers are in the legend, switched off.',
            $bare->get('map')->note,
        );
        self::assertStringContainsString('Unbeatable for spotting a cluster;', $copy->mapGroundThesis([]));
    }

    /**
     * THE OTHER DIRECTION OF THE ENUMERATION. The loops above draw what the seam
     * declares; this one proves the seam declares what the host actually ships,
     * so a partial written and never wired up — or wired up and never written —
     * fails here rather than 500ing the first time somebody switches it on.
     */
    public function testEveryHostWidgetPartialOnDiskIsReachedThroughTheSeam(): void
    {
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        \assert(\is_string($projectDir));

        $onDisk = glob($projectDir.'/templates/'.self::HOST_TEMPLATE_DIR.'/_w_*.html.twig');
        \assert(\is_array($onDisk));
        $onDisk = array_map(
            static fn (string $path): string => self::HOST_TEMPLATE_DIR.'/'.basename($path),
            $onDisk,
        );
        sort($onDisk);

        $reached = [];
        foreach ($this->partials as $partial) {
            if (str_starts_with($partial, self::HOST_TEMPLATE_DIR)) {
                $reached[] = $partial;
            }
        }
        sort($reached);

        self::assertSame($onDisk, $reached, 'A host widget partial is not reachable through the catalogue.');
    }

    // ── The fixtures ───────────────────────────────────────────────────────────

    /**
     * A morning, in the shapes the seam carries.
     *
     * @return array<string, mixed>
     */
    private function populated(): array
    {
        $now = self::now();
        $layers = $this->layersOfEveryShape();

        $tiles = [
            new NowTile('PL·N1', 'patrols', 'Out right now', '3', 'endulen · naabi · nainokanoka', live: true, url: '/patrols', priority: 10),
            new NowTile('PL·N2', 'patrols', 'Kilometres today', '48', 'against 61 the same day last week', unit: 'km', priority: 20),
            new NowTile('IN·N1', 'incidents', 'Open incidents', '7', '2 past their term', alarm: '2 past their term', tone: NowTile::TONE_BAD, url: '/incidents', priority: 30),
            new NowTile('IN·N2', 'incidents', 'Reported today', '4', 'snaring · grazing', tone: NowTile::TONE_HOT, priority: 40),
        ];

        $attention = [
            new AttentionItem(AttentionSeverity::Now, 'incidents', 'Incidents', 'Snare line at the forest edge', 'past term', '17 d', 1_468_800, '/incidents/INC-0008', 'nobody has been assigned', ['INC-0008', 'Endulen']),
            new AttentionItem(AttentionSeverity::Soon, 'patrols', 'Patrols', 'A patrol has stopped pinging', 'live position', '2 h 10', 7_800, '/patrols/P-0145', 'last seen near the crater rim', ['P-0145']),
            new AttentionItem(AttentionSeverity::Watch, 'patrols', 'Patrols', 'Nobody has entered Nainokanoka', 'coverage gap', '9 d', 777_600, '/patrols/gaps'),
        ];

        $pulse = [
            new PulseEvent($now->modify('-40 minutes'), 'patrols', 'Patrols', 'P-0145', 'patrol opened', 'Walking round out of Endulen', '/patrols/P-0145', '#3f8f5f', meta: ['Endulen', 'Leah Saitoti']),
            new PulseEvent($now->modify('-3 hours'), 'incidents', 'Incidents', 'INC-0316', 'reported → verified', 'Snaring reported on the forest edge', '/incidents/INC-0316', '#b4472f', state: 'verified', stateClass: 'verified', meta: ['Endulen']),
            new PulseEvent($now->modify('-16 hours'), 'incidents', 'Incidents', 'INC-0315', 'incident reported', 'Cattle inside the zone at dusk', '/incidents/INC-0315', '#b4472f', state: 'reported', stateClass: 'reported'),
        ];

        return [
            ...$this->withLayers($layers),
            'tiles' => [
                ...$tiles,
                ...$this->service(AreaOverviewComposer::class)->hostSummaryTiles($attention, '/areas/'.$this->area->getUuidString()),
            ],
            'attention' => $attention,
            'pulse' => [
                ['day' => $now->setTime(0, 0), 'events' => [$pulse[0], $pulse[1]]],
                ['day' => $now->modify('-1 day')->setTime(0, 0), 'events' => [$pulse[2]]],
            ],
        ];
    }

    /**
     * THE FIVE SHAPES, ON THE PLATES THAT CARRY THEM — plus the layer with
     * nothing to draw, which still ships its legend entry.
     *
     * Every one of these is a shape a real module puts on this surface today:
     * an incident and a live position are Points, a patrol track is a
     * LineString, a track with dropped GPS is a MultiLineString, a zone is a
     * Polygon and a coverage buffer is a MultiPolygon.
     *
     * @return list<MapLayer>
     */
    private function layersOfEveryShape(): array
    {
        return [
            new MapLayer('host.boundary', 'host', 'The area', 'Boundary', '#1f6f5c', $this->collection([
                ['Polygon', [[[35.0, -3.4], [35.8, -3.4], [35.8, -2.9], [35.0, -2.9], [35.0, -3.4]]], ['ref' => 'NCA', 'title' => 'Ngorongoro Conservation Area']],
            ]), MapLayer::STYLE_BOUNDARY),
            new MapLayer('host.zones', 'host', 'The area', 'Zones', '#4a7f9c', $this->collection([
                ['Polygon', [[[35.1, -3.3], [35.3, -3.3], [35.3, -3.1], [35.1, -3.1], [35.1, -3.3]]], ['ref' => 'Z-01', 'title' => 'Endulen', 'place' => 'north', 'url' => '/zones/Z-01']],
            ]), count: 1),
            new MapLayer('patrols.tracks', 'patrols', 'Patrols', 'Today’s tracks', '#3f8f5f', $this->collection([
                ['LineString', [[35.2, -3.2], [35.25, -3.18], [35.31, -3.15]], ['ref' => 'P-0145', 'title' => 'Walking round', 'place' => 'Endulen', 'when' => '05:20', 'state' => 'recording']],
                ['MultiLineString', [[[35.4, -3.05], [35.44, -3.02]], [[35.5, -2.98], [35.56, -2.95]]], ['ref' => 'P-0146', 'title' => 'Vehicle round, GPS dropped twice', 'place' => 'Naabi', 'when' => '06:02']],
                // A LAYER MAY SAY NOTHING ABOUT A FEATURE. The host prints the
                // properties a layer states and interprets none of them, so a
                // bare geometry has to dock as a row with no words rather than
                // as a 500.
                ['LineString', [[35.6, -3.3], [35.66, -3.27]], []],
            ]), MapLayer::STYLE_LINE, count: 3, live: true),
            new MapLayer('patrols.positions', 'patrols', 'Patrols', 'Live positions', '#2f6f4f', $this->collection([
                ['Point', [35.31, -3.15], ['ref' => 'P-0145', 'title' => 'Leah Saitoti', 'when' => '11:30', 'url' => '/patrols/P-0145']],
            ]), count: 1, live: true),
            new MapLayer('patrols.coverage', 'patrols', 'Patrols', 'Coverage buffer', '#7fae8f', $this->collection([
                ['MultiPolygon', [[[[35.2, -3.22], [35.33, -3.22], [35.33, -3.13], [35.2, -3.13], [35.2, -3.22]]], [[[35.4, -3.07], [35.58, -3.07], [35.58, -2.93], [35.4, -2.93], [35.4, -3.07]]]], ['ref' => '1 km of today’s tracks']],
            ]), count: 1, on: false),
            new MapLayer('incidents.open', 'incidents', 'Incidents', 'Open', '#b4472f', $this->collection([
                ['Point', [35.22, -3.19], ['ref' => 'INC-0316', 'title' => 'Snaring on the forest edge', 'place' => 'Endulen', 'when' => '3 h ago', 'state' => 'verified', 'url' => '/incidents/INC-0316']],
            ]), count: 1, live: true),
            // A FEATURE WITH NO POINT OF ITS OWN. GeoJSON allows an unlocated
            // feature (`"geometry": null`), and a GeometryCollection carries
            // `geometries` rather than `coordinates` — so neither can be asked
            // for a coordinate. The plate already knows what to do with a row
            // that has no point: it keeps it, exactly as it keeps a line that
            // crosses the edge of the viewport.
            new MapLayer('incidents.unlocated', 'incidents', 'Incidents', 'Reported without a place', '#c98a2f', [
                'type' => 'FeatureCollection',
                'features' => [
                    ['type' => 'Feature', 'geometry' => null, 'properties' => ['ref' => 'INC-0317', 'title' => 'Reported by radio, place not given']],
                    ['type' => 'Feature', 'geometry' => ['type' => 'GeometryCollection', 'geometries' => [['type' => 'Point', 'coordinates' => [35.2, -3.2]]]], 'properties' => ['ref' => 'INC-0318', 'title' => 'A sighting and the track that found it']],
                ],
            ], count: 2),
            // NOTHING TO DRAW, AND STILL IN THE LEGEND.
            new MapLayer('incidents.closed', 'incidents', 'Incidents', 'Closed this week', '#8a8a8a', $this->collection([]), count: 0, on: false),
        ];
    }

    /**
     * The page's context with these layers on the plate — and the legend the
     * host builds from them, so the two cannot disagree.
     *
     * @param list<MapLayer> $layers
     *
     * @return array<string, mixed>
     */
    private function withLayers(array $layers): array
    {
        return [
            ...$this->context,
            'layers' => $layers,
            'legend' => $this->service(AreaOverviewComposer::class)->legend($layers),
        ];
    }

    /**
     * @param list<array{0: string, 1: mixed, 2?: array<string, string>}> $features
     *
     * @return array{type: string, features: list<array<string, mixed>>}
     */
    private function collection(array $features): array
    {
        return [
            'type' => 'FeatureCollection',
            'features' => array_map(
                // A feature with nothing said about it carries NO `properties`
                // key at all, which is what a bare GeoJSON writer emits — not an
                // empty map the template would find waiting for it.
                static fn (array $feature): array => array_filter([
                    'type' => 'Feature',
                    'geometry' => ['type' => $feature[0], 'coordinates' => $feature[1]],
                    'properties' => $feature[2] ?? [],
                ]),
                $features,
            ),
        ];
    }

    // ── The plumbing ───────────────────────────────────────────────────────────

    /**
     * ONE RENDER, AND A PHP WARNING IS A FAILURE. `data-lon="Array"` shipped
     * with a warning beside it; a suite that lets one through is a suite that
     * would have shipped it again.
     *
     * @param array<string, mixed> $context
     */
    private function render(string $partial, array $context): string
    {
        $twig = self::getContainer()->get('twig');
        \assert($twig instanceof Environment);

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            // `with_context: false` is how the surface includes a widget: one
            // map, nothing ambient, whoever wrote the partial.
            return $twig->render($partial, $context);
        } catch (\Throwable $e) {
            self::fail(\sprintf('%s does not render: %s', $partial, $e->getMessage()));
        } finally {
            restore_error_handler();
        }
    }

    /** The one fixed moment every reading on the page is taken at. */
    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-03-21 11:40:00');
    }

    private function seedAndInstallEveryModule(): void
    {
        $kernel = self::$kernel;
        \assert($kernel instanceof KernelInterface);

        $application = new Application($kernel);
        $application->setAutoExit(false);
        $application->run(new ArrayInput(['command' => 'app:seed:catalogue']), new NullOutput());

        // The seed parks a bundle's module so an admin opts in per area. This
        // test is the opted-in case: every widget the deployment can draw.
        $em = $this->service(EntityManagerInterface::class);
        foreach ($this->service(AreaModuleRepository::class)->forArea($this->area) as $areaModule) {
            $areaModule->setActive(true);
        }
        $em->flush();
    }

    /** @return list<string> */
    private function installedSlugs(): array
    {
        $slugs = [];
        foreach ($this->service(AreaModuleRepository::class)->activeForArea($this->area) as $areaModule) {
            $slug = $areaModule->getModule()?->getSlug();
            if (null !== $slug) {
                $slugs[] = $slug;
            }
        }

        self::assertNotSame([], $slugs, 'No module is installed, so no contributed widget would be drawn.');

        return $slugs;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        \assert($service instanceof $id);

        return $service;
    }
}
