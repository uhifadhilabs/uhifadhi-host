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

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Model\WidgetCatalog;
use Uhifadhi\Model\WidgetDom;
use Uhifadhi\Repository\AreaModuleRepository;
use Uhifadhi\Service\AreaOverviewCatalogue;
use Uhifadhi\Service\AreaOverviewComposer;
use Uhifadhi\Service\WidgetEndpoint;
use Uhifadhi\Service\WidgetService;

/**
 * THE AREA OVERVIEW — `/areas/{uuid}`, the widget surface the host owns and
 * almost nothing on which is the host's.
 *
 * What this controller does is assemble and hand over: it asks which modules the
 * area has, builds the catalogue from that, resolves the person's active preset
 * over it, collects the contributed parts, and renders. It contains NO WIDGET
 * MARKUP, names no module and knows no module's numbers — uninstalling a module
 * removes its widgets from the library, its tiles from the strip, its items from
 * needs attention and its layers from the map, and a saved preset that named
 * them degrades rather than breaking, without a line here changing.
 *
 * READING IS FOR EVERYONE WHO CAN REACH THE AREA. This is the page an area
 * manager opens at 07:00; a gate on it would be a gate on the area. What each
 * module chooses to show inside its own widget is that module's business.
 *
 * AREA-SCOPED PREFERENCES: every widget-framework call passes this area's uuid,
 * so one person may lead Ngorongoro with the map and Pololeti with the queue.
 */
#[Route('/areas/{uuid}', requirements: ['uuid' => Requirement::UUID])]
final class AreaOverviewController extends AbstractController
{
    public function __construct(
        private readonly AreaOverviewCatalogue $catalogue,
        private readonly AreaOverviewComposer $composer,
        private readonly AreaModuleRepository $areaModules,
        private readonly WidgetService $widgets,
        private readonly WidgetEndpoint $widgetEndpoint,
    ) {
    }

    #[Route('', name: 'dashboard_area_show', methods: ['GET'])]
    public function show(#[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area): Response
    {
        $installed = $this->installedSlugs($area);
        $catalog = $this->catalogue->for($installed);

        return $this->render('area/overview/show.html.twig', [
            'area' => $area,
            'widgets' => $this->widgets->resolve($catalog, $this->userId(), $area->getUuid()),
            'partials' => $this->catalogue->partialsFor($installed),
            'widgetContext' => $this->widgetContext($area, $installed),
        ]);
    }

    /**
     * THE SURFACE'S ONE POLLING ENDPOINT, and the only one it will ever have.
     *
     * An overview with six independent pollers is a load test. So exactly one
     * route refreshes exactly what wears the live dot: the right-now strip, and
     * the map layers whose owner said they move. A layer that does not move —
     * the boundary, the zones, a coverage buffer — is not in the answer, because
     * re-fetching a polygon that has not changed since it was gazetted is the
     * clearest possible waste.
     *
     * The strip comes back as ITS OWN MARKUP rather than as numbers, so a
     * refreshed tile cannot be drawn differently from a rendered one.
     */
    #[Route('/overview/now', name: 'app_area_overview_now', methods: ['GET'], priority: 2)]
    public function now(#[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area): Response
    {
        $installed = $this->installedSlugs($area);

        $layers = [];
        foreach ($this->composer->mapLayers($area, $installed, new \DateTimeImmutable()) as $layer) {
            if ($layer->live) {
                $layers[] = ['id' => $layer->id, 'features' => $layer->features];
            }
        }

        return $this->json([
            'strip' => $this->renderView('area/overview/_w_nowbar.html.twig', $this->widgetContext($area, $installed)),
            'layers' => $layers,
        ]);
    }

    // ── The widget library: the preset component, on this surface ──────────────

    #[Route('/overview/widgets', name: 'app_area_overview_widgets', methods: ['GET'], priority: 2)]
    public function widgets(#[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area): Response
    {
        $installed = $this->installedSlugs($area);
        $catalog = $this->catalogue->for($installed);
        $userId = $this->userId();
        $areaUuid = $area->getUuid();

        return $this->render('area/overview/widgets.html.twig', [
            'area' => $area,
            'catalog' => $catalog,
            'builtins' => $catalog->builtins(),
            'customPresets' => $this->widgets->customPresets($catalog, $userId, $areaUuid),
            'active' => $this->widgets->activeRef($catalog, $userId, $areaUuid),
            'widgets' => $this->widgets->resolve($catalog, $userId, $areaUuid),
            'partial' => $this->catalogue->partialsFor($installed),
            'widgetContext' => $this->widgetContext($area, $installed),
            'urls' => $this->widgetUrls($area),
            'csrfToken' => $this->widgetEndpoint->csrfToken($catalog, $areaUuid),
        ]);
    }

    #[Route('/overview/widgets/save', name: 'app_area_overview_widgets_save', methods: ['POST'], priority: 2)]
    public function widgetsSave(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        return $this->widgetEndpoint->save($request, $this->catalogFor($area), $area->getUuid());
    }

    #[Route('/overview/widgets/reset', name: 'app_area_overview_widgets_reset', methods: ['POST'], priority: 2)]
    public function widgetsReset(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        return $this->widgetEndpoint->reset($request, $this->catalogFor($area), $area->getUuid());
    }

    #[Route('/overview/widgets/preset/{presetId}', name: 'app_area_overview_widgets_preset', requirements: ['presetId' => '[a-z0-9_-]+'], methods: ['POST'], priority: 2)]
    public function widgetsPreset(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
        string $presetId,
    ): Response {
        $catalog = $this->catalogFor($area);

        return $this->afterPresetWrite(
            $area,
            $this->widgetEndpoint->applyPreset($request, $catalog, $presetId, $area->getUuid()),
            \sprintf('This area’s overview now follows “%s”.', $catalog->preset($presetId)?->label),
            'dashboard_area_show',
        );
    }

    /**
     * Copy one of the five directions, to customize. The designs the surface
     * ships are immutable, so this is the only door from one into an editable
     * layout, and the copy becomes active because customizing the design you are
     * looking at means customizing the one you are on.
     */
    #[Route('/overview/widgets/preset/{presetId}/copy', name: 'app_area_overview_widgets_preset_copy', requirements: ['presetId' => '[a-z0-9_-]+'], methods: ['POST'], priority: 3)]
    public function widgetsPresetCopy(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
        string $presetId,
    ): Response {
        return $this->afterPresetWrite(
            $area,
            $this->widgetEndpoint->copyPreset($request, $this->catalogFor($area), $presetId, $area->getUuid()),
            'Copied. The copy is yours to edit, and this area’s overview is on it.',
            'app_area_overview_widgets',
        );
    }

    #[Route('/overview/widgets/presets', name: 'app_area_overview_widgets_preset_create', methods: ['POST'], priority: 2)]
    public function widgetsPresetCreate(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        return $this->afterPresetWrite(
            $area,
            $this->widgetEndpoint->createCustomPreset($request, $this->catalogFor($area), $area->getUuid()),
            'Saved. This area’s overview is on your own arrangement.',
            'app_area_overview_widgets',
        );
    }

    #[Route('/overview/widgets/presets/{presetUuid}/apply', name: 'app_area_overview_widgets_preset_apply', requirements: ['presetUuid' => Requirement::UUID], methods: ['POST'], priority: 2)]
    public function widgetsPresetApply(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
        string $presetUuid,
    ): Response {
        return $this->afterPresetWrite(
            $area,
            $this->widgetEndpoint->applyCustomPreset($request, $this->catalogFor($area), Uuid::fromString($presetUuid), $area->getUuid()),
            'This area’s overview is on your saved arrangement.',
            'dashboard_area_show',
        );
    }

    #[Route('/overview/widgets/presets/{presetUuid}/rename', name: 'app_area_overview_widgets_preset_rename', requirements: ['presetUuid' => Requirement::UUID], methods: ['POST'], priority: 2)]
    public function widgetsPresetRename(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
        string $presetUuid,
    ): Response {
        return $this->afterPresetWrite(
            $area,
            $this->widgetEndpoint->renameCustomPreset($request, $this->catalogFor($area), Uuid::fromString($presetUuid), $area->getUuid()),
            'Renamed.',
            'app_area_overview_widgets',
        );
    }

    #[Route('/overview/widgets/presets/{presetUuid}/delete', name: 'app_area_overview_widgets_preset_delete', requirements: ['presetUuid' => Requirement::UUID], methods: ['POST'], priority: 2)]
    public function widgetsPresetDelete(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
        string $presetUuid,
    ): Response {
        return $this->afterPresetWrite(
            $area,
            $this->widgetEndpoint->deleteCustomPreset($request, $this->catalogFor($area), Uuid::fromString($presetUuid), $area->getUuid()),
            'Deleted. This area’s overview is back on a design the surface ships.',
            'app_area_overview_widgets',
        );
    }

    /**
     * EXACTLY what every partial on this surface receives — the dashboard's and
     * the library's, from one call, so a plate can never read differently in the
     * two.
     *
     * A CONTRIBUTOR'S OWN VARIABLES ARE UNDER ITS SLUG (`by.patrols`), which is
     * how twenty widgets from three owners share one context without a name
     * collision and without the host having to know what any of them mean. The
     * merged parts — the strip's tiles, the attention list, the map's layers and
     * legend, the pulse — are shared, because they are the host's own widgets'
     * content.
     *
     * @param list<string> $installed
     *
     * @return array<string, mixed>
     */
    private function widgetContext(AreaOfInterest $area, array $installed): array
    {
        $now = new \DateTimeImmutable();

        $by = [];
        $contributions = [];
        foreach ($this->catalogue->contributorsFor($installed) as $contributor) {
            $slug = $contributor->moduleSlug();
            $by[$slug] = $contributor->context($area, $now);
            $contributions[$slug] = ['widgets' => \count($contributor->widgets()), 'tiles' => 0, 'attention' => 0, 'layers' => 0];
        }

        $attention = $this->composer->attention($area, $installed, $now);
        $layers = $this->composer->mapLayers($area, $installed, $now);
        $attentionUrl = $this->generateUrl('dashboard_area_show', ['uuid' => $area->getUuidString()]);
        $tiles = [
            ...$this->composer->nowTiles($area, $installed, $now),
            ...$this->composer->hostSummaryTiles($attention, $attentionUrl),
        ];

        // WHAT EACH MODULE PUT ON THIS PAGE, COUNTED rather than described — a
        // module whose provider went quiet says so on the "Modules in this area"
        // card instead of still claiming three tiles it no longer returns.
        foreach ([['tiles', $tiles], ['attention', $attention], ['layers', $layers]] as [$kind, $parts]) {
            foreach ($parts as $part) {
                if (isset($contributions[$part->moduleSlug])) {
                    ++$contributions[$part->moduleSlug][$kind];
                }
            }
        }

        return [
            'area' => $area,
            'now' => $now,
            'by' => $by,
            'tiles' => $tiles,
            'contributions' => $contributions,
            'attention' => $attention,
            'layers' => $layers,
            'legend' => $this->composer->legend($layers),
            'pulse' => $this->composer->pulse($area, $installed, $now),
            'urls' => [
                'settings' => $this->generateUrl('dashboard_area_settings', ['uuid' => $area->getUuidString()]),
                'modules' => $this->generateUrl('dashboard_area_modules_grid', ['uuid' => $area->getUuidString()]),
                'customize' => $this->generateUrl('dashboard_area_modules', ['uuid' => $area->getUuidString()]),
                'zones' => $this->generateUrl('app_area_zones', ['uuid' => $area->getUuidString()]),
            ],
        ];
    }

    /**
     * The area's modules, in its own order — asked ONCE per request, so the
     * catalogue, the composer and the page can never disagree about what the
     * area has.
     *
     * @return list<string>
     */
    private function installedSlugs(AreaOfInterest $area): array
    {
        $slugs = [];
        foreach ($this->areaModules->activeForArea($area) as $areaModule) {
            $slug = $areaModule->getModule()?->getSlug();
            if (null !== $slug) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    private function catalogFor(AreaOfInterest $area): WidgetCatalog
    {
        return $this->catalogue->for($this->installedSlugs($area));
    }

    /**
     * THE LIBRARY'S WIRE, as URLs — the same map every surface hands the shared
     * component, with this AREA named in every one of them.
     *
     * @return array<string, string>
     */
    private function widgetUrls(AreaOfInterest $area): array
    {
        $id = WidgetDom::ID_PLACEHOLDER;
        $uuid = ['uuid' => $area->getUuidString()];

        return [
            'save' => $this->generateUrl('app_area_overview_widgets_save', $uuid),
            'reset' => $this->generateUrl('app_area_overview_widgets_reset', $uuid),
            'preset' => $this->generateUrl('app_area_overview_widgets_preset', [...$uuid, 'presetId' => $id]),
            'copy' => $this->generateUrl('app_area_overview_widgets_preset_copy', [...$uuid, 'presetId' => $id]),
            'presets' => $this->generateUrl('app_area_overview_widgets_preset_create', $uuid),
            'apply' => $this->generateUrl('app_area_overview_widgets_preset_apply', [...$uuid, 'presetUuid' => $id]),
            'rename' => $this->generateUrl('app_area_overview_widgets_preset_rename', [...$uuid, 'presetUuid' => $id]),
            'delete' => $this->generateUrl('app_area_overview_widgets_preset_delete', [...$uuid, 'presetUuid' => $id]),
            'dashboard' => $this->generateUrl('dashboard_area_show', $uuid),
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
}
