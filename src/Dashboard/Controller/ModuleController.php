<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use App\Composition\Entity\AreaModule;
use App\Composition\Enum\VizType;
use App\Composition\Repository\VisualizationRepository;
use App\Composition\Service\AreaCompositionService;
use App\Dashboard\Service\AreaModuleService;
use App\Dashboard\Service\DatasetPresenter;
use App\Dashboard\Module\DatasetChartRenderer;
use App\Dashboard\Service\ModuleMethodService;
use App\Dashboard\Service\ModuleOverviewService;
use App\Dashboard\Service\ModuleVizDefaults;
use App\Forest\Service\ForestChartService;
use App\Forest\Service\ForestLossSummaryService;
use App\Ingestion\Entity\Dataset;
use App\Ingestion\Message\RunModuleIngestion;
use App\Ingestion\Repository\DatasetRepository;
use App\Ingestion\Repository\DatasetRunRepository;
use App\Ingestion\Repository\ModuleFeatureRepository;
use App\Spatial\Entity\AreaOfInterest;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * A per-area analytical module page (a self-contained sub-app): its own within-module tabs
 * (Overview / Dataframe / Explore / Method / Settings), an "All modules" way back to the area,
 * and per-tab bodies. Overview renders the module's headline view — real data for a live module,
 * a scaffold for a template one. Dataframe / Explore / Method render the module's data once it is
 * wired (see the Dataset pipeline). Settings is the module's Data page — run ingestion (map detail /
 * coarseness), current resolution, recent runs and datasets. The area Overview is the area show page,
 * not routed here.
 */
final class ModuleController extends AbstractController
{
    /** The tabs served by this controller (the whole within-module IA). */
    private const VIEW_TABS = 'overview|dataframe|explore|method|settings';

    /** The stats resolution the engine runs at, in metres — the map detail factor multiplies it. */
    private const STATS_RES_M = 30;

    #[Route(
        '/areas/{uuid}/{module}/{tab}',
        name: 'dashboard_area_module',
        requirements: ['uuid' => Requirement::UUID, 'module' => '[a-z]+', 'tab' => self::VIEW_TABS],
        defaults: ['tab' => 'overview'],
        methods: ['GET'],
    )]
    #[IsGranted('module.view')]
    public function show(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $module,
        string $tab,
        AreaModuleService $modules,
        ForestLossSummaryService $forestLoss,
        ForestChartService $charts,
        DatasetRunRepository $runs,
        DatasetRepository $datasets,
        DatasetPresenter $presenter,
        ModuleMethodService $methods,
        ModuleOverviewService $overview,
        ModuleFeatureRepository $features,
        AreaCompositionService $composition,
        VisualizationRepository $vizRepo,
        ModuleVizDefaults $vizDefaults,
        DatasetChartRenderer $chartRenderer,
        Request $request,
    ): Response {
        $descriptor = $modules->page($area, $module);
        if (null === $descriptor) {
            throw $this->createNotFoundException(\sprintf('Unknown module "%s".', $module));
        }

        // The one bespoke live module renders its real Hansen series across all six plots on Overview.
        $forest = null;
        $forestCharts = null;
        if ('overview' === $tab && 'forest' === $module) {
            $forest = $forestLoss->forArea($area);
            $statuses = array_map(
                static fn ($run) => (string) $run->getStatus(),
                $runs->findBy(['aoi' => $area], ['id' => 'DESC']),
            );
            $forestCharts = [
                'cumulative' => $charts->cumulative($forest['lossByYear']),
                'waterfall' => $charts->waterfall($forest['lossByYear']),
                'trend' => $charts->trend($forest['lossByYear']),
                'coverage' => $charts->coverage($forest['lossByYear']),
                'shelf' => $charts->shelf($statuses),
            ];
        }

        // Overview & Settings work with the module's configured visualizations — seeded once with the
        // module's defaults, so the Overview renders the SAME charts you edit in Settings (never a
        // hardcoded one). Resolve the composed module and materialise its defaults on first view.
        $areaModule = null;
        $overviewCharts = [];
        if (\in_array($tab, ['overview', 'settings'], true)) {
            $areaModule = $this->areaModuleFor($composition, $area, $module);
            if (null !== $areaModule) {
                $vizDefaults->ensure($areaModule);
            }
        }

        // Overview / Dataframe / Explore read the module's stored tabular dataset (the rows the cockpit,
        // viewer and describe() work on).
        $table = null;
        $cockpit = null;
        $describe = [];
        $distribution = [];
        if (\in_array($tab, ['overview', 'dataframe', 'explore'], true)) {
            foreach ($datasets->forModule($area, $module) as $dataset) {
                if ($dataset->getKind()->isTabular()) {
                    $table = $dataset;
                    break;
                }
            }
            if (null !== $table) {
                $cockpit = 'overview' === $tab ? $overview->cockpit($table) : null;
                if (\in_array($tab, ['dataframe', 'explore'], true)) {
                    $describe = $presenter->describe($table);
                    $distribution = $this->distribution($table, $presenter);
                }
            }
        }

        // The Overview's charts ARE the module's configured visualizations, drawn through the generic
        // renderer (an unbound/not-yet-drawable one is skipped, not faked).
        if ('overview' === $tab && 'forest' !== $module && null !== $areaModule) {
            foreach ($areaModule->getVisualizations() as $viz) {
                $svg = $chartRenderer->render($area, $viz);
                if (null !== $svg) {
                    $overviewCharts[] = ['title' => (string) $viz->getTitle(), 'type' => $viz->getType()->label(), 'svg' => $svg];
                }
            }
        }

        // The map (Overview + Explore) reuses the area boundary + the module's dissolved layer, if any.
        $boundary = null;
        $mapLayerUrl = null;
        if (\in_array($tab, ['overview', 'explore'], true)) {
            $boundary = $this->boundary($area);
            $layer = $features->forLayer($area, $module, $module.'_map');
            if ([] !== $layer) {
                $mapLayerUrl = $this->generateUrl('module_layer_geojson', [
                    'uuid' => $area->getUuidString(), 'module' => $module, 'key' => $module.'_map',
                ]);
            }
        }

        // The current map detail/resolution comes from the run that produced the layer.
        $lastRun = \in_array($tab, ['overview', 'settings'], true)
            ? $runs->findOneBy(['aoi' => $area, 'dataset' => $module], ['id' => 'DESC'])
            : null;

        // Settings is the module's one Data + Visualizations page: recent runs, the datasets on the
        // shelf, and this module's visualizations (with the configure modal on ?configure).
        $moduleRuns = [];
        $moduleDatasets = [];
        $visualizations = [];
        $configureViz = null;
        if ('settings' === $tab) {
            $moduleRuns = $runs->findBy(['aoi' => $area, 'dataset' => $module], ['id' => 'DESC'], 8);
            $moduleDatasets = $datasets->forModule($area, $module);
            if (null !== $areaModule) {
                $visualizations = $areaModule->getVisualizations();
                $configure = $request->query->get('configure');
                if (\is_string($configure) && Uuid::isValid($configure)) {
                    $candidate = $vizRepo->findOneBy(['uuid' => Uuid::fromString($configure)]);
                    $configureViz = $candidate?->getAreaModule()?->getId() === $areaModule->getId() ? $candidate : null;
                }
            }
        }

        return $this->render('dashboard/module.html.twig', [
            'area' => $area,
            'module' => $descriptor,
            'activeTab' => $tab,
            'table' => $table,
            'tableTypes' => null !== $table ? $presenter->types($table) : [],
            'tableNumeric' => null !== $table ? $presenter->numericColumns($table) : [],
            'cockpit' => $cockpit,
            'overviewCharts' => $overviewCharts,
            'lastRun' => $lastRun,
            'mapDetail' => $this->mapDetail($lastRun),
            'moduleRuns' => $moduleRuns,
            'moduleDatasets' => $moduleDatasets,
            'visualizations' => $visualizations,
            'configureViz' => $configureViz,
            'vizColumns' => $this->vizColumns($moduleDatasets),
            'vizTypes' => VizType::editable(),
            'hasMapLayer' => [] !== $features->forLayer($area, $module, $module.'_map'),
            'describe' => $describe,
            'distribution' => $distribution,
            'boundary' => $boundary,
            'mapLayerUrl' => $mapLayerUrl,
            'method' => 'method' === $tab ? $methods->forModule($module) : null,
            'forest' => $forest,
            'forestCharts' => $forestCharts,
        ]);
    }

    /**
     * Run (or re-run) a module's ingestion from the UI — async, like the Hansen trigger. The map-detail
     * factor (coarseness) comes from the form; the worker hands it to the engine.
     */
    #[Route('/areas/{uuid}/{module}/run', name: 'dashboard_area_module_run', requirements: ['uuid' => Requirement::UUID, 'module' => '[a-z]+'], methods: ['POST'])]
    #[IsGranted('ingestion.run')]
    public function run(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $module,
        Request $request,
        MessageBusInterface $bus,
    ): Response {
        $this->assertCsrf($request, 'module_run_'.$module);
        $detail = (int) $request->request->get('detail', 4);
        $detail = max(1, min(16, $detail)); // clamp to a sane range

        $bus->dispatch(new RunModuleIngestion((int) $area->getId(), $module, [
            'res_m' => (float) self::STATS_RES_M,
            'display_factor' => $detail,
        ]));
        $this->addFlash('success', \sprintf('Ingestion queued for %s (map detail ×%d).', $module, $detail));

        return $this->redirectToRoute('dashboard_area_module', [
            'uuid' => $area->getUuidString(), 'module' => $module, 'tab' => 'settings',
        ]);
    }

    /** The active AreaModule for (area, slug), or null if the module isn't composed on the area. */
    private function areaModuleFor(AreaCompositionService $composition, AreaOfInterest $area, string $module): ?AreaModule
    {
        foreach ($composition->activeFor($area) as $areaModule) {
            if ($areaModule->getModule()?->getSlug() === $module) {
                return $areaModule;
            }
        }

        return null;
    }

    /**
     * The column names a visualization can bind to — the first tabular dataset's columns.
     *
     * @param list<Dataset> $datasets
     *
     * @return list<string>
     */
    private function vizColumns(array $datasets): array
    {
        foreach ($datasets as $dataset) {
            if ($dataset->getKind()->isTabular() && null !== $dataset->getColumns()) {
                return array_values($dataset->getColumns());
            }
        }

        return [];
    }

    /**
     * The current map detail from a run's params: the display factor and the metres it resolves to.
     *
     * @return array{factor: int, metres: int}|null
     */
    private function mapDetail(?object $run): ?array
    {
        if (!$run instanceof \App\Ingestion\Entity\DatasetRun) {
            return null;
        }
        $params = $run->getParams()['params'] ?? null;
        $factor = \is_array($params) && is_numeric($params['display_factor'] ?? null) ? (int) $params['display_factor'] : 4;

        return ['factor' => $factor, 'metres' => $factor * self::STATS_RES_M];
    }

    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /**
     * The area boundary as a GeoJSON FeatureCollection for the Leaflet map (as the area Overview builds it).
     *
     * @return array<string, mixed>
     */
    private function boundary(AreaOfInterest $area): array
    {
        $geom = $area->getGeom();

        return [
            'type' => 'FeatureCollection',
            'features' => null === $geom ? [] : [[
                'type' => 'Feature',
                'properties' => ['name' => $area->getName()],
                'geometry' => json_decode($geom, true, 512, \JSON_THROW_ON_ERROR),
            ]],
        ];
    }

    /**
     * The distribution the Explore tab plots: the first numeric column's values, sorted high→low.
     *
     * @return list<float>
     */
    private function distribution(Dataset $table, DatasetPresenter $presenter): array
    {
        $numeric = $presenter->numericColumns($table);
        if ([] === $numeric) {
            return [];
        }
        $columns = $table->getColumns() ?? [];
        $index = array_search($numeric[0], $columns, true);
        $values = [];
        foreach ($table->getRows() ?? [] as $row) {
            $v = $row[$index] ?? null;
            if (\is_int($v) || \is_float($v)) {
                $values[] = (float) $v;
            }
        }
        rsort($values);

        return $values;
    }
}
