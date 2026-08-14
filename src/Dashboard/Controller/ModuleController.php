<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use App\Dashboard\Service\AreaModuleService;
use App\Dashboard\Service\DatasetPresenter;
use App\Dashboard\Service\ModuleMethodService;
use App\Forest\Service\ForestChartService;
use App\Forest\Service\ForestLossSummaryService;
use App\Ingestion\Entity\Dataset;
use App\Ingestion\Repository\DatasetRepository;
use App\Ingestion\Repository\DatasetRunRepository;
use App\Spatial\Entity\AreaOfInterest;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A per-area analytical module page (a self-contained sub-app): its own within-module tabs
 * (Overview / Dataframe / Explore / Method / Settings), an "All modules" way back to the area,
 * and per-tab bodies. Overview renders the module's headline view — real data for a live module,
 * a scaffold for a template one. Dataframe / Explore / Method render the module's data once it is
 * wired (see the Dataset pipeline). The area Overview is the area show page, not routed here; the
 * Settings tab links to the module edit surface ({@see ModuleEditController}, guarded module.create).
 */
final class ModuleController extends AbstractController
{
    /** The view tabs served by this controller. Settings lives on the edit surface, so it is not here. */
    private const VIEW_TABS = 'overview|dataframe|explore|method';

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
    ): Response {
        $descriptor = $modules->page($area, $module);
        if (null === $descriptor) {
            throw $this->createNotFoundException(\sprintf('Unknown module "%s".', $module));
        }

        // Only the Overview tab needs the module's headline data; the other view tabs render their
        // own body (Dataframe / Explore / Method) off the Dataset pipeline, wired per module.
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

        // Dataframe & Explore read the module's stored datasets: the first tabular one (the rows the
        // viewer and describe() work on) and the first spatial one (the Explore map layer).
        $table = null;
        $spatial = null;
        $describe = [];
        $distribution = [];
        if (\in_array($tab, ['dataframe', 'explore'], true)) {
            foreach ($datasets->forModule($area, $module) as $dataset) {
                if (null === $table && $dataset->getKind()->isTabular()) {
                    $table = $dataset;
                }
                if (null === $spatial && $dataset->getKind()->isSpatial()) {
                    $spatial = $dataset;
                }
            }
            if (null !== $table) {
                $describe = $presenter->describe($table);
                $distribution = $this->distribution($table, $presenter);
            }
        }

        return $this->render('dashboard/module.html.twig', [
            'area' => $area,
            'module' => $descriptor,
            'activeTab' => $tab,
            'table' => $table,
            'tableTypes' => null !== $table ? $presenter->types($table) : [],
            'tableNumeric' => null !== $table ? $presenter->numericColumns($table) : [],
            'describe' => $describe,
            'distribution' => $distribution,
            'spatial' => $spatial,
            'method' => 'method' === $tab ? $methods->forModule($module) : null,
            'forest' => $forest,
            'forestCharts' => $forestCharts,
        ]);
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
