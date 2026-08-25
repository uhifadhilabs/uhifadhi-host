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

namespace Uhifadhi\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\Zone;
use Uhifadhi\Exception\ZoneImportException;
use Uhifadhi\Model\WidgetDom;
use Uhifadhi\Model\ZonePalette;
use Uhifadhi\Model\ZonesWidgets;
use Uhifadhi\Repository\AreaOfInterestRepository;
use Uhifadhi\Repository\ZoneRepository;
use Uhifadhi\Service\WidgetEndpoint;
use Uhifadhi\Service\WidgetService;
use Uhifadhi\Service\ZoneImportService;

/**
 * The per-area ZONES surface: the widget dashboard, its library, the GeoJSON its map eats, and
 * the three writes that administer a zoning scheme (import, rename, delete).
 *
 * READING is for everyone who can reach the area, exactly like the area Overview it sits beside:
 * a zone is a lens, and a lens nobody may look through explains nothing. ADMINISTERING is
 * Manager-and-up, as /departments and /team are; the templates get that same answer as
 * `canManage`, so the chrome and the endpoint can never disagree.
 *
 * PRESENCE-DRIVEN. An area with no zones does not get a dashboard of empty plates — it gets one
 * plate saying what a zone is and offering the one way to make some. That decision is made HERE,
 * once, from `zonesFor($area) === []`, and it is why nothing zone-shaped leaks into an
 * un-zoned area anywhere else in the product.
 *
 * AREA-SCOPED preferences: every widget-framework call passes this area's UUID, so one person's
 * Ngorongoro layout and their Pololeti layout are two rows and never one.
 *
 * The Zone MODEL layer is consumed, never re-implemented: {@see ZoneRepository::zonesFor()} for
 * the set, its inherited `stAreaKm2()` for the geodesic areas, {@see ZoneImportService} for the
 * all-or-nothing import whose messages are surfaced VERBATIM — the model layer already says which
 * zone, why, and which zone it collided with, and re-wording that here would only make it worse.
 */
#[Route('/areas/{uuid}/zones', requirements: ['uuid' => Requirement::UUID])]
final class ZonesController extends AbstractController
{
    /** One id for every management write on this surface — they are one capability, one tier. */
    private const string MANAGE_TOKEN = 'zone_manage';

    /** The width the gallery's mini-maps are projected into; the height follows the AOI's shape. */
    private const int MINI_W = 700;

    public function __construct(
        private readonly ZoneRepository $zones,
        private readonly EntityManagerInterface $em,
        private readonly AreaOfInterestRepository $areas,
        private readonly ZoneImportService $importer,
        private readonly WidgetService $widgets,
        private readonly WidgetEndpoint $widgetEndpoint,
    ) {
    }

    // ── The dashboard ──────────────────────────────────────────────────────────────────────

    #[Route('', name: 'app_area_zones', methods: ['GET'])]
    public function index(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $selected = $request->query->getString('zone');

        return $this->render('zones/index.html.twig', [
            ...$this->context($area, '' === $selected ? null : $selected),
            'widgets' => $this->widgets->resolve(ZonesWidgets::catalog(), $this->userId(), $area->getUuid()),
        ]);
    }

    /**
     * The same dashboard with one zone selected — what a picker row, a register row and a card
     * all link to. A separate route rather than a fragment so the selection is a place: it can be
     * linked to, and the split manager's detail pane survives a reload.
     */
    #[Route('/{zoneUuid}', name: 'app_area_zone', requirements: ['zoneUuid' => Requirement::UUID], methods: ['GET'])]
    public function show(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $zoneUuid,
    ): Response {
        return $this->render('zones/index.html.twig', [
            ...$this->context($area, $zoneUuid),
            'widgets' => $this->widgets->resolve(ZonesWidgets::catalog(), $this->userId(), $area->getUuid()),
        ]);
    }

    /**
     * The zone layer, as the map's own format. One feature per zone, in name order, each carrying
     * the name, the plate, the geodesic area and — deliberately — ITS COLOUR: the legend, the
     * picker swatch and the polygon all read that one value, so the same zone can never be two
     * colours on one screen.
     *
     * An unzoned area answers with an empty FeatureCollection, not a 404: "this area has no
     * zones" is the normal state, and a map that draws nothing is the correct rendering of it.
     */
    #[Route('.geojson', name: 'app_area_zones_geojson', methods: ['GET'], priority: 1)]
    public function geojson(#[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area): JsonResponse
    {
        $features = [];
        foreach ($this->rows($area) as $row) {
            $features[] = [
                'type' => 'Feature',
                'properties' => [
                    'uuid' => $row['uuid'],
                    'name' => $row['name'],
                    'plate' => $row['plate'],
                    'color' => $row['color'],
                    'areaKm2' => $row['areaKm2'],
                    'sharePct' => $row['sharePct'],
                ],
                'geometry' => $row['geometry'],
            ];
        }

        $response = new JsonResponse(['type' => 'FeatureCollection', 'features' => $features]);
        // The map fetches it, so say what it is: geo+json, and never cached across areas.
        $response->headers->set('Content-Type', 'application/geo+json');

        return $response;
    }

    // ── The widget library, area-scoped throughout ─────────────────────────────────────────

    #[Route('/widgets', name: 'app_area_zones_widgets', methods: ['GET'], priority: 2)]
    public function widgets(#[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area): Response
    {
        $catalog = ZonesWidgets::catalog();
        $areaUuid = $area->getUuid();
        $userId = $this->userId();

        return $this->render('zones/widgets.html.twig', [
            ...$this->context($area, null),
            // Everything templates/widgets/_library.html.twig is parameterised by — the shared
            // preset component, whole, over this surface's catalogue and this AREA's routes.
            // Nothing in it knows the word "zone", which is the point.
            'catalog' => $catalog,
            'builtins' => $catalog->builtins(),
            'customPresets' => $this->widgets->customPresets($catalog, $userId, $areaUuid),
            'active' => $this->widgets->activeRef($catalog, $userId, $areaUuid),
            'widgets' => $this->widgets->resolve($catalog, $userId, $areaUuid),
            'partial' => 'zones/_w_%s.html.twig',
            'urls' => $this->widgetUrls($area),
            'csrfToken' => $this->widgetEndpoint->csrfToken($catalog, $areaUuid),
        ]);
    }

    #[Route('/widgets/save', name: 'app_area_zones_widgets_save', methods: ['POST'], priority: 2)]
    public function widgetsSave(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        return $this->widgetEndpoint->save($request, ZonesWidgets::catalog(), $area->getUuid());
    }

    #[Route('/widgets/reset', name: 'app_area_zones_widgets_reset', methods: ['POST'], priority: 2)]
    public function widgetsReset(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        return $this->widgetEndpoint->reset($request, ZonesWidgets::catalog(), $area->getUuid());
    }

    #[Route('/widgets/preset/{presetId}', name: 'app_area_zones_widgets_preset', requirements: ['presetId' => '[a-z0-9_-]+'], methods: ['POST'], priority: 2)]
    public function widgetsPreset(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
        string $presetId,
    ): Response {
        $catalog = ZonesWidgets::catalog();

        return $this->afterPresetWrite(
            $area,
            $this->widgetEndpoint->applyPreset($request, $catalog, $presetId, $area->getUuid()),
            \sprintf('This area’s zone dashboard now follows “%s”.', $catalog->preset($presetId)?->label),
            'app_area_zones',
        );
    }

    /**
     * Make a copy of one of the five directions, to customize — for THIS area. The designs the
     * surface ships are immutable, so this is the only door from one into an editable layout, and
     * the copy becomes active because customizing the design you are looking at means customizing
     * the one you are on.
     */
    #[Route('/widgets/preset/{presetId}/copy', name: 'app_area_zones_widgets_preset_copy', requirements: ['presetId' => '[a-z0-9_-]+'], methods: ['POST'], priority: 3)]
    public function widgetsPresetCopy(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
        string $presetId,
    ): Response {
        return $this->afterPresetWrite(
            $area,
            $this->widgetEndpoint->copyPreset($request, ZonesWidgets::catalog(), $presetId, $area->getUuid()),
            'Copied. The copy is yours to edit, and this area’s dashboard is on it.',
            'app_area_zones_widgets',
        );
    }

    #[Route('/widgets/presets', name: 'app_area_zones_widgets_preset_create', methods: ['POST'], priority: 2)]
    public function widgetsPresetCreate(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        return $this->afterPresetWrite(
            $area,
            $this->widgetEndpoint->createCustomPreset($request, ZonesWidgets::catalog(), $area->getUuid()),
            'Saved. Your layout is in “My presets”.',
            'app_area_zones_widgets',
        );
    }

    #[Route('/widgets/presets/{presetUuid}/apply', name: 'app_area_zones_widgets_preset_apply', requirements: ['presetUuid' => Requirement::UUID], methods: ['POST'], priority: 2)]
    public function widgetsPresetApply(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
        string $presetUuid,
    ): Response {
        return $this->afterPresetWrite(
            $area,
            $this->widgetEndpoint->applyCustomPreset($request, ZonesWidgets::catalog(), Uuid::fromString($presetUuid), $area->getUuid()),
            'This area’s zone dashboard now follows your saved preset.',
            'app_area_zones',
        );
    }

    #[Route('/widgets/presets/{presetUuid}/rename', name: 'app_area_zones_widgets_preset_rename', requirements: ['presetUuid' => Requirement::UUID], methods: ['POST'], priority: 2)]
    public function widgetsPresetRename(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
        string $presetUuid,
    ): Response {
        return $this->afterPresetWrite(
            $area,
            $this->widgetEndpoint->renameCustomPreset($request, ZonesWidgets::catalog(), Uuid::fromString($presetUuid), $area->getUuid()),
            'Renamed.',
            'app_area_zones_widgets',
        );
    }

    #[Route('/widgets/presets/{presetUuid}/delete', name: 'app_area_zones_widgets_preset_delete', requirements: ['presetUuid' => Requirement::UUID], methods: ['POST'], priority: 2)]
    public function widgetsPresetDelete(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
        string $presetUuid,
    ): Response {
        return $this->afterPresetWrite(
            $area,
            $this->widgetEndpoint->deleteCustomPreset($request, ZonesWidgets::catalog(), Uuid::fromString($presetUuid), $area->getUuid()),
            'Preset deleted. Your dashboard is untouched.',
            'app_area_zones_widgets',
        );
    }

    // ── The three writes ───────────────────────────────────────────────────────────────────

    /**
     * A whole zoning scheme, in one move or not at all.
     *
     * Everything this can refuse, the model layer already refused in the admin's own words — an
     * overlap naming BOTH zones, a feature with no `name`, a repeated name, a geometry that is
     * not a polygon. So the message is passed through untouched: re-phrasing it here would lose
     * the one detail that makes it actionable, which zone to go and fix.
     */
    #[Route('/import', name: 'app_area_zones_import', methods: ['POST'], priority: 2)]
    #[IsGranted('ROLE_MANAGER')]
    public function import(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $this->denyUnlessTokenValid($request);

        $file = $request->files->get('zones');
        if (!$file instanceof UploadedFile) {
            $this->addFlash('error', 'Choose a GeoJSON FeatureCollection to import — one named polygon per zone.');

            return $this->redirectToRoute('app_area_zones', ['uuid' => $area->getUuidString()]);
        }

        try {
            $imported = $this->importer->importFile($area, $file->getPathname());
            $this->addFlash('success', \sprintf(
                '%d zone%s imported. They are a lens, not a fence: nothing is hidden or blocked because of where it falls.',
                \count($imported),
                1 === \count($imported) ? '' : 's',
            ));
        } catch (ZoneImportException $e) {
            // VERBATIM, prefixed by the one thing the message itself cannot say: that the rest of
            // the file was rejected with it and the area is exactly as it was.
            $this->addFlash('error', 'Nothing was imported — the whole file is rejected together. '.$e->getMessage());
        }

        return $this->redirectToRoute('app_area_zones', ['uuid' => $area->getUuidString()]);
    }

    /**
     * A zone's name is unique within its AREA, not the org — two areas may each have a "Crater",
     * so the collision this can hit is a local one and says so.
     */
    #[Route('/{zoneUuid}/rename', name: 'app_area_zone_rename', requirements: ['zoneUuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function rename(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        #[MapEntity(mapping: ['zoneUuid' => 'uuid'])] Zone $zone,
        Request $request,
    ): Response {
        $this->denyUnlessTokenValid($request);
        $this->denyUnlessZoneBelongsTo($area, $zone);

        $name = trim($request->request->getString('name'));
        $was = (string) $zone->getName();
        if ('' === $name) {
            $this->addFlash('error', 'A zone needs a name.');
        } elseif ($name === $was) {
            $this->addFlash('success', 'Nothing to change — that is already its name.');
        } elseif (null !== $this->zones->findOneForName($area, $name)) {
            $this->addFlash('error', \sprintf('This area already has a zone named “%s” — zone names are unique within an area.', $name));
        } else {
            $zone->setName($name);
            $this->em->flush();
            $this->addFlash('success', \sprintf('“%s” is now “%s”. Every number it groups moved with it; no record did.', $was, $name));
        }

        return $this->redirectToRoute('app_area_zone', ['uuid' => $area->getUuidString(), 'zoneUuid' => (string) $zone->getUuidString()]);
    }

    /** A lens change only: the records this zone grouped keep existing, ungrouped. */
    #[Route('/{zoneUuid}/delete', name: 'app_area_zone_delete', requirements: ['zoneUuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function delete(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        #[MapEntity(mapping: ['zoneUuid' => 'uuid'])] Zone $zone,
        Request $request,
    ): Response {
        $this->denyUnlessTokenValid($request);
        $this->denyUnlessZoneBelongsTo($area, $zone);

        $name = (string) $zone->getName();
        $this->em->remove($zone);
        $this->em->flush();
        $this->addFlash('success', \sprintf('“%s” deleted. Everything it grouped is still here — it simply stops being grouped under that name.', $name));

        return $this->redirectToRoute('app_area_zones', ['uuid' => $area->getUuidString()]);
    }

    // ── The one context every partial receives ─────────────────────────────────────────────

    /**
     * EXACTLY what the dashboard, the library and every widget partial read — built once so the
     * two screens can never render the same widget from two different sets of numbers.
     *
     * @return array{
     *     area: AreaOfInterest,
     *     zones: list<array<string, mixed>>,
     *     selected: array<string, mixed>|null,
     *     areaKm2: float,
     *     zonedKm2: float,
     *     coveragePct: float,
     *     canManage: bool,
     *     geojsonUrl: string,
     * }
     */
    private function context(AreaOfInterest $area, ?string $selectedUuid): array
    {
        $box = $this->boundingBox((string) $area->getGeom());
        $rows = $this->rows($area, $box);

        $selected = null;
        foreach ($rows as $row) {
            if ($row['uuid'] === $selectedUuid) {
                $selected = $row;
                break;
            }
        }
        // The split manager always has something in its detail pane: with no explicit choice the
        // first zone by name is the selection, which is also what the design draws.
        $selected ??= $rows[0] ?? null;

        $areaKm2 = $this->areas->stAreaKm2(['id' => $area->getId()]);
        // Zones never share interior, so the union's area IS the sum of the parts — no ST_Union
        // needed, and the invariant is what makes the shortcut sound.
        $zonedKm2 = array_sum(array_column($rows, 'areaKm2'));

        return [
            'area' => $area,
            'zones' => $rows,
            'selected' => $selected,
            'areaKm2' => $areaKm2,
            'zonedKm2' => $zonedKm2,
            'coveragePct' => $areaKm2 > 0.0 ? round($zonedKm2 / $areaKm2 * 100, 1) : 0.0,
            'canManage' => $this->isGranted('ROLE_MANAGER'),
            'geojsonUrl' => $this->generateUrl('app_area_zones_geojson', ['uuid' => $area->getUuidString()]),
            // The card gallery's mini-maps: the AOI's own bounding box as a viewBox, and every
            // outline already projected into it, so twelve cards cost no tiles and still draw the
            // same shapes the real map does.
            'viewBox' => \sprintf('0 0 %d %d', self::MINI_W, (int) round(self::MINI_W * $box['ratio'])),
            'aoiPath' => $this->path((string) $area->getGeom(), $box),
            'aoiColor' => ZonePalette::AOI,
        ];
    }

    /**
     * Every zone of the area with the facts the host can actually answer: its name, its address,
     * its geodesic area, its share of the AOI, its ring count and its layer colour.
     *
     * Name-ordered, because that is the order {@see ZoneRepository::zonesFor()} returns and the
     * order every list in the design shows. The COLOUR, though, is indexed by creation order, so
     * renaming a zone never repaints it and a re-sorted list keeps its swatches.
     *
     * @param array{minLon: float, minLat: float, spanLon: float, spanLat: float, ratio: float} $box
     *
     * @return list<array<string, mixed>>
     */
    private function rows(AreaOfInterest $area, ?array $box = null): array
    {
        $box ??= $this->boundingBox((string) $area->getGeom());
        $zones = $this->zones->zonesFor($area);

        $byCreation = $zones;
        usort($byCreation, static fn (Zone $a, Zone $b): int => ($a->getId() ?? 0) <=> ($b->getId() ?? 0));
        $paletteIndex = [];
        foreach ($byCreation as $index => $zone) {
            $paletteIndex[(int) $zone->getId()] = $index;
        }

        $areaKm2 = $this->areas->stAreaKm2(['id' => $area->getId()]);

        $rows = [];
        foreach ($zones as $zone) {
            $index = $paletteIndex[(int) $zone->getId()];
            $km2 = round($this->zones->stAreaKm2(['id' => $zone->getId()]), 1);
            $rows[] = [
                'zone' => $zone,
                'uuid' => (string) $zone->getUuidString(),
                'name' => (string) $zone->getName(),
                'plate' => ZonePalette::plate($index),
                'color' => ZonePalette::color($index),
                'areaKm2' => $km2,
                'sharePct' => $areaKm2 > 0.0 ? round($km2 / $areaKm2 * 100, 1) : 0.0,
                'vertices' => $this->vertices((string) $zone->getGeom()),
                // Decoded once, here: the endpoint nests it as the feature's geometry and nothing
                // downstream has to parse a string that is already known to be JSON.
                'geometry' => json_decode((string) $zone->getGeom(), true, 512, \JSON_THROW_ON_ERROR),
                'path' => $this->path((string) $zone->getGeom(), $box),
            ];
        }

        return $rows;
    }

    /**
     * The AOI's bounding box, and the aspect the mini-maps must keep.
     *
     * Latitude is squeezed by cos(lat) at this scale, so a plate-carrée box would draw every shape
     * stretched east–west. The ratio corrects it — this is a thumbnail, not a projection library,
     * and the real map is Leaflet's Web Mercator.
     *
     * @return array{minLon: float, minLat: float, spanLon: float, spanLat: float, ratio: float}
     */
    private function boundingBox(string $geomJson): array
    {
        $lons = [];
        $lats = [];
        foreach ($this->rings($geomJson) as $ring) {
            foreach ($ring as $point) {
                $lons[] = (float) $point[0];
                $lats[] = (float) $point[1];
            }
        }
        if ([] === $lons || [] === $lats) {
            return ['minLon' => 0.0, 'minLat' => 0.0, 'spanLon' => 1.0, 'spanLat' => 1.0, 'ratio' => 0.618];
        }

        $minLon = min($lons);
        $minLat = min($lats);
        $spanLon = max(1e-9, max($lons) - $minLon);
        $spanLat = max(1e-9, max($lats) - $minLat);
        $squeeze = max(0.05, cos(deg2rad(($minLat + max($lats)) / 2)));

        return [
            'minLon' => $minLon,
            'minLat' => $minLat,
            'spanLon' => $spanLon,
            'spanLat' => $spanLat,
            'ratio' => $spanLat / ($spanLon * $squeeze),
        ];
    }

    /**
     * One SVG path for every outer ring of the geometry, projected into the AOI's box. Y is
     * inverted because latitude grows north and SVG's y grows down.
     *
     * @param array{minLon: float, minLat: float, spanLon: float, spanLat: float, ratio: float} $box
     */
    private function path(string $geomJson, array $box): string
    {
        $height = self::MINI_W * $box['ratio'];
        $path = '';
        foreach ($this->rings($geomJson) as $ring) {
            $commands = [];
            foreach ($ring as $point) {
                $x = ((float) $point[0] - $box['minLon']) / $box['spanLon'] * self::MINI_W;
                $y = $height - ((float) $point[1] - $box['minLat']) / $box['spanLat'] * $height;
                $commands[] = \sprintf('%s%.1f %.1f', [] === $commands ? 'M' : 'L', $x, $y);
            }
            if ([] !== $commands) {
                $path .= implode(' ', $commands).' Z ';
            }
        }

        return trim($path);
    }

    /**
     * The outer ring of every polygon in a MultiPolygon. Holes are dropped: at thumbnail size an
     * un-filled hole reads as noise, and the real map draws the geometry in full.
     *
     * @return list<list<array{0: float, 1: float}>>
     */
    private function rings(string $geomJson): array
    {
        try {
            /** @var array{coordinates?: list<list<list<array{0: float, 1: float}>>>} $geometry */
            $geometry = json_decode($geomJson, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        $rings = [];
        foreach ($geometry['coordinates'] ?? [] as $polygon) {
            if (isset($polygon[0]) && [] !== $polygon[0]) {
                $rings[] = $polygon[0];
            }
        }

        return $rings;
    }

    /**
     * How many points the outer rings carry — the one shape fact the design shows and the host
     * can compute without another round trip. The closing point repeats the first, so it is not
     * counted twice.
     */
    private function vertices(string $geomJson): int
    {
        $count = 0;
        foreach ($this->rings($geomJson) as $ring) {
            $count += max(0, \count($ring) - 1);
        }

        return $count;
    }

    /**
     * THE LIBRARY'S WIRE, as URLs — the same map every surface hands the shared component, with
     * this AREA named in every one of them: arranging Ngorongoro's zone dashboard can never
     * rearrange Pololeti's, and that is stated in the URLs rather than trusted to a check.
     * A template carries {@see WidgetDom::ID_PLACEHOLDER} where the id goes.
     *
     * @return array<string, string>
     */
    private function widgetUrls(AreaOfInterest $area): array
    {
        $id = WidgetDom::ID_PLACEHOLDER;
        $uuid = ['uuid' => $area->getUuidString()];

        return [
            'save' => $this->generateUrl('app_area_zones_widgets_save', $uuid),
            'reset' => $this->generateUrl('app_area_zones_widgets_reset', $uuid),
            'preset' => $this->generateUrl('app_area_zones_widgets_preset', [...$uuid, 'presetId' => $id]),
            'copy' => $this->generateUrl('app_area_zones_widgets_preset_copy', [...$uuid, 'presetId' => $id]),
            'presets' => $this->generateUrl('app_area_zones_widgets_preset_create', $uuid),
            'apply' => $this->generateUrl('app_area_zones_widgets_preset_apply', [...$uuid, 'presetUuid' => $id]),
            'rename' => $this->generateUrl('app_area_zones_widgets_preset_rename', [...$uuid, 'presetUuid' => $id]),
            'delete' => $this->generateUrl('app_area_zones_widgets_preset_delete', [...$uuid, 'presetUuid' => $id]),
            'dashboard' => $this->generateUrl('app_area_zones', $uuid),
        ];
    }

    private function afterPresetWrite(AreaOfInterest $area, Response $response, string $flash, string $route): Response
    {
        if (Response::HTTP_NO_CONTENT !== $response->getStatusCode()) {
            return $response;
        }

        $this->addFlash('success', $flash);

        return $this->redirectToRoute($route, ['uuid' => $area->getUuidString()]);
    }

    /** The signed-in person's id, whose layout this is. Null is impossible behind the firewall. */
    private function userId(): int
    {
        return $this->widgetEndpoint->userId();
    }

    private function denyUnlessTokenValid(Request $request): void
    {
        $token = $request->request->get('_token');
        if (!\is_string($token) || !$this->isCsrfTokenValid(self::MANAGE_TOKEN, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /**
     * A zone UUID in one area's URL must be one of THAT area's zones — otherwise the route would
     * happily rename another park's "Crater" through this park's page.
     */
    private function denyUnlessZoneBelongsTo(AreaOfInterest $area, Zone $zone): void
    {
        if ($zone->getArea()?->getId() !== $area->getId()) {
            throw $this->createNotFoundException('That zone belongs to another area.');
        }
    }
}
