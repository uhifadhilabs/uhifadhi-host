<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use App\Dashboard\Service\AreaModuleService;
use App\Forest\Service\ForestChartService;
use App\Forest\Service\ForestLossSummaryService;
use App\Ingestion\Repository\DatasetRunRepository;
use App\Spatial\Entity\AreaOfInterest;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * A per-area analytical module page (a sub-app module): live modules render real
 * data, template modules show their scaffold until their ingestion lands. The
 * Overview module is the area show page, so it is not routed here.
 */
final class ModuleController extends AbstractController
{
    #[Route(
        '/areas/{uuid}/{module}',
        name: 'dashboard_area_module',
        requirements: ['uuid' => Requirement::UUID, 'module' => '[a-z]+'],
        methods: ['GET'],
    )]
    public function show(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $module,
        AreaModuleService $modules,
        ForestLossSummaryService $forestLoss,
        ForestChartService $charts,
        DatasetRunRepository $runs,
    ): Response {
        $descriptor = $modules->page($module);
        if (null === $descriptor) {
            throw $this->createNotFoundException(\sprintf('Unknown module "%s".', $module));
        }

        // The one live module renders its real series across all six plots; templates need no data.
        $forest = null;
        $forestCharts = null;
        if ('forest' === $module) {
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

        return $this->render('dashboard/module.html.twig', [
            'area' => $area,
            'module' => $descriptor,
            'modules' => $modules->modules(),
            'planned' => $modules->planned(),
            'forest' => $forest,
            'forestCharts' => $forestCharts,
        ]);
    }
}
