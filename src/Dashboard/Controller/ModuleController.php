<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use App\Dashboard\Service\AreaModuleService;
use App\Forest\Service\ForestLossSummaryService;
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
    ): Response {
        $descriptor = $modules->page($module);
        if (null === $descriptor) {
            throw $this->createNotFoundException(\sprintf('Unknown module "%s".', $module));
        }

        // The one live module renders its real series; templates need no data.
        $forest = 'forest' === $module ? $forestLoss->forArea($area) : null;

        return $this->render('dashboard/module.html.twig', [
            'area' => $area,
            'module' => $descriptor,
            'modules' => $modules->modules(),
            'planned' => $modules->planned(),
            'forest' => $forest,
        ]);
    }
}
