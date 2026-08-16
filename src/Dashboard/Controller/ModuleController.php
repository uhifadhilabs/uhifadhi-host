<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use App\Composition\Entity\AreaModule;
use App\Composition\Enum\VizType;
use App\Composition\Repository\VisualizationRepository;
use App\Composition\Service\AreaCompositionService;
use App\Dashboard\Module\DatasetChartRenderer;
use App\Dashboard\Module\ModuleRegistry;
use App\Dashboard\Service\AreaModuleService;
use App\Dashboard\Service\DatasetPresenter;
use App\Dashboard\Service\ModuleOverviewService;
use App\Dashboard\Service\ModuleVizDefaults;
use App\Ingestion\Entity\Dataset;
use App\Ingestion\Entity\DatasetRun;
use App\Ingestion\Enum\DatasetKind;
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
 * (Overview / Dataframe / Explore / Method / Settings), an "All modules" way back to the area, and
 * per-tab bodies. Everything renders GENERICALLY, driven by the module's {@see \App\Spatial\Module\ModuleDefinition}
 * (KPIs, default charts, method caption, palette — resolved by slug through {@see ModuleRegistry})
 * plus the module's stored datasets. No module is ever named here: adding a module means adding a
 * tagged definition + an engine module + a catalogue row — never editing this controller.
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
        ModuleRegistry $registry,
        DatasetRunRepository $runs,
        DatasetRepository $datasets,
        DatasetPresenter $presenter,
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
        $definition = $registry->definitionFor($module);

        // The module's configured visualizations — seeded once from its definition's defaults, so the
        // Overview renders the SAME charts edited in Settings.
        $areaModule = null;
        if (\in_array($tab, ['overview', 'settings'], true)) {
            $areaModule = $this->areaModuleFor($composition, $area, $module);
            if (null !== $areaModule) {
                $vizDefaults->ensure($areaModule);
            }
        }

        // The module's tabular datasets — a module may own several (Q4); `?dataset=<key>` selects
        // which one the viewer / describe() / KPIs work on (default: the first).
        $tables = [];
        $table = null;
        $describe = [];
        $distribution = [];
        if (\in_array($tab, ['overview', 'dataframe', 'explore'], true)) {
            foreach ($datasets->forModule($area, $module) as $dataset) {
                if ($dataset->getKind()->isTabular()) {
                    $tables[] = $dataset;
                }
            }
            $wanted = $request->query->get('dataset');
            foreach ($tables as $candidate) {
                if ($candidate->getKey() === $wanted) {
                    $table = $candidate;
                }
            }
            $table ??= $tables[0] ?? null;
            if (null !== $table && \in_array($tab, ['dataframe', 'explore'], true)) {
                $describe = $presenter->describe($table);
                $distribution = $this->distribution($table, $presenter);
            }
        }

        $lastRun = \in_array($tab, ['overview', 'settings'], true)
            ? $runs->findOneBy(['aoi' => $area, 'dataset' => $module], ['id' => 'DESC'])
            : null;

        // Overview: KPIs from the module's definition (its own bounded context computes them), or
        // derived generically from the dataframe when the definition supplies none. Charts are the
        // configured visualizations drawn by the generic renderer.
        $kpis = [];
        $overviewCharts = [];
        if ('overview' === $tab) {
            $kpis = $definition->kpis($area) ?: $overview->deriveKpis($table, $lastRun);
            if (null !== $areaModule) {
                foreach ($areaModule->getVisualizations() as $viz) {
                    $svg = $chartRenderer->render($area, $viz);
                    if (null !== $svg) {
                        $overviewCharts[] = ['title' => (string) $viz->getTitle(), 'type' => $viz->getType()->label(), 'svg' => $svg];
                    }
                }
            }
        }

        // The map (Overview + Explore): area boundary + the module's dissolved vector layer and/or
        // its raster surface, whichever the engine produced.
        $boundary = null;
        $mapLayerUrl = null;
        $rasterUrl = null;
        $rasterBounds = null;
        $legend = [];
        if (\in_array($tab, ['overview', 'explore'], true)) {
            $boundary = $this->boundary($area);
            $layerFeatures = $features->forLayer($area, $module, $definition->mapDatasetKey());
            if ([] !== $layerFeatures) {
                $mapLayerUrl = $this->generateUrl('module_layer_geojson', [
                    'uuid' => $area->getUuidString(), 'module' => $module, 'key' => $definition->mapDatasetKey(),
                ]);
                $legend = $this->legend($layerFeatures, $definition->palette(), $table);
            }
            foreach ($datasets->forModule($area, $module) as $dataset) {
                $bounds = $dataset->getMeta()['bounds'] ?? null;
                if (DatasetKind::Raster === $dataset->getKind() && null !== $dataset->getPayload() && \is_array($bounds)) {
                    $rasterUrl = $this->generateUrl('module_layer_raster', [
                        'uuid' => $area->getUuidString(), 'module' => $module, 'key' => (string) $dataset->getKey(),
                    ]);
                    $rasterBounds = $bounds;
                    break;
                }
            }
        }

        // Settings: the module's one Data + Visualizations page.
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
            'kpis' => $kpis,
            'overviewCharts' => $overviewCharts,
            'palette' => $definition->palette(),
            'method' => 'method' === $tab ? $definition->methodCaption() : null,
            'table' => $table,
            'datasetKeys' => array_map(static fn (Dataset $d): ?string => $d->getKey(), $tables),
            'tableTypes' => null !== $table ? $presenter->types($table) : [],
            'tableNumeric' => null !== $table ? $presenter->numericColumns($table) : [],
            'describe' => $describe,
            'distribution' => $distribution,
            'boundary' => $boundary,
            'legend' => $legend,
            'mapLayerUrl' => $mapLayerUrl,
            'rasterUrl' => $rasterUrl,
            'rasterBounds' => $rasterBounds,
            'lastRun' => $lastRun,
            'mapDetail' => $this->mapDetail($lastRun),
            'moduleRuns' => $moduleRuns,
            'moduleDatasets' => $moduleDatasets,
            'visualizations' => $visualizations,
            'configureViz' => $configureViz,
            'vizColumns' => $this->vizColumnMeta($moduleDatasets, $presenter),
            'vizTypes' => VizType::editable(),
            'hasMapLayer' => [] !== $features->forLayer($area, $module, $definition->mapDatasetKey()),
        ]);
    }

    /**
     * Run (or re-run) a module's ingestion from the UI — async. The map-detail factor (coarseness)
     * comes from the form; the worker hands it to the engine.
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
     * The area boundary as a GeoJSON FeatureCollection for the Leaflet map.
     *
     * @return array<string, mixed>
     */
    /**
     * The map legend, driven by the layer's actual dissolved labels (not the dataframe): each label
     * coloured by the definition's palette, with a value attached only when the module's first table
     * is genuinely class-shaped (its first column contains that label).
     *
     * @param list<\App\Ingestion\Entity\ModuleFeature> $layerFeatures
     * @param array<string, string>                     $palette
     *
     * @return list<array{label: string, color: string, value: string|null}>
     */
    private function legend(array $layerFeatures, array $palette, ?Dataset $table): array
    {
        $units = ['area_km2' => ' km²', 'ha' => ' ha', 'length_km' => ' km', 'km2' => ' km²'];
        $values = [];
        $valueSuffix = '';
        if (null !== $table) {
            $columns = $table->getColumns() ?? [];
            $valueSuffix = $units[$columns[1] ?? ''] ?? '';
            foreach ($table->getRows() ?? [] as $row) {
                // Keys are stringified so integer labels (forest's years) match the layer's labels.
                if (\is_array($row) && \is_scalar($row[0] ?? null) && !\is_bool($row[0]) && is_numeric($row[1] ?? null)) {
                    $values[(string) $row[0]] = (float) $row[1];
                }
            }
        }

        $labels = array_values(array_unique(array_map(
            static fn ($feature) => (string) $feature->getLabel(),
            $layerFeatures,
        )));
        // All-numeric labels (years) read chronologically; class labels read largest-first.
        if ([] !== $labels && array_all($labels, static fn (string $label): bool => is_numeric($label))) {
            usort($labels, static fn (string $a, string $b): int => (float) $a <=> (float) $b);
        } else {
            usort($labels, static function (string $a, string $b) use ($values): int {
                return ($values[$b] ?? -\INF) <=> ($values[$a] ?? -\INF) ?: strcmp($a, $b);
            });
        }

        return array_map(static fn (string $label): array => [
            'label' => $label,
            'color' => $palette[$label] ?? '#888888',
            'value' => isset($values[$label])
                ? number_format($values[$label], $values[$label] < 10 ? 2 : 0).$valueSuffix
                : null,
        ], $labels);
    }

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
     * The columns a visualization can bind to, PER tabular dataset — name + inferred type. The
     * configure form offers the dataset select from `keys` and swaps the column options from `byKey`
     * when it changes; types constrain what each chart type may plot.
     *
     * @param list<Dataset> $datasets
     *
     * @return array{keys: list<string>, byKey: array<string, list<array{name: string, type: string}>>}
     */
    private function vizColumnMeta(array $datasets, DatasetPresenter $presenter): array
    {
        $keys = [];
        $byKey = [];
        foreach ($datasets as $dataset) {
            $key = $dataset->getKey();
            if (null === $key || !$dataset->getKind()->isTabular() || null === $dataset->getColumns()) {
                continue;
            }
            $types = $presenter->types($dataset);
            $columns = [];
            foreach (array_values($dataset->getColumns()) as $i => $name) {
                $columns[] = ['name' => (string) $name, 'type' => $types[$i] ?? 'chr'];
            }
            $keys[] = $key;
            $byKey[$key] = $columns;
        }

        return ['keys' => $keys, 'byKey' => $byKey];
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

    /**
     * The current map detail from a run's params: the display factor and the metres it resolves to.
     *
     * @return array{factor: int, metres: int}|null
     */
    private function mapDetail(?DatasetRun $run): ?array
    {
        if (null === $run) {
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
}
