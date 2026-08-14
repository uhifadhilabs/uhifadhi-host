<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use App\Dashboard\Service\ModuleGridService;
use App\Spatial\Entity\AreaOfInterest;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The area's Modules tab: every active module as a content-ful card, grouped by zone. A card opens
 * the module's own page; composing the set (add / remove / reorder) is the "Customize" action, which
 * lives on the module-shop ({@see CustomizeModulesController}) and needs the stronger module.create.
 * Viewing the grid needs only module.view.
 */
final class AreaModulesController extends AbstractController
{
    #[Route(
        '/areas/{uuid}/modules',
        name: 'dashboard_area_modules_grid',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('module.view')]
    public function grid(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        ModuleGridService $grid,
    ): Response {
        return $this->render('dashboard/modules_grid.html.twig', [
            'area' => $area,
            'groups' => $grid->grouped($area),
        ]);
    }
}
