<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use App\Composition\Repository\AreaModuleRepository;
use App\Composition\Repository\ModuleRepository;
use App\Composition\Service\AreaCompositionService;
use App\Spatial\Entity\AreaOfInterest;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * "Customize modules" — the per-area module shop: choose which modules appear on an area, reorder
 * them, and add catalogue modules. Composing an area's sub-app is the `module.create` capability.
 */
#[Route('/areas/{uuid}/modules/customize', requirements: ['uuid' => Requirement::UUID])]
#[IsGranted('module.create')]
final class CustomizeModulesController extends AbstractController
{
    public function __construct(
        private readonly AreaCompositionService $composition,
        private readonly AreaModuleRepository $areaModules,
        private readonly ModuleRepository $modules,
    ) {
    }

    #[Route('', name: 'dashboard_area_modules', methods: ['GET'])]
    public function index(#[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area): Response
    {
        return $this->render('dashboard/modules.html.twig', [
            'area' => $area,
            'active' => $this->composition->activeFor($area),
            'parkedByCategory' => $this->composition->parkedByCategory($area),
            'parkedCount' => \count($this->composition->parkedFor($area)),
        ]);
    }

    #[Route('/{areaModuleUuid}/toggle', name: 'dashboard_area_module_toggle', requirements: ['areaModuleUuid' => Requirement::UUID], methods: ['POST'])]
    public function toggle(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $areaModuleUuid,
        Request $request,
    ): Response {
        $this->assertCsrf($request, 'module_toggle_'.$areaModuleUuid);
        $areaModule = $this->areaModules->findOneBy(['uuid' => Uuid::fromString($areaModuleUuid)]);
        if (null !== $areaModule && $areaModule->getArea()?->getId() === $area->getId()) {
            $this->composition->setActive($areaModule, !$areaModule->isActive());
        }

        return $this->redirectToRoute('dashboard_area_modules', ['uuid' => $area->getUuidString()]);
    }

    #[Route('/add', name: 'dashboard_area_module_add', methods: ['POST'])]
    public function add(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $this->assertCsrf($request, 'module_add_'.$area->getUuidString());
        $slug = $request->request->get('module');
        $module = \is_string($slug) ? $this->modules->findBySlug($slug) : null;
        if (null !== $module) {
            $this->composition->addToArea($area, $module);
        }

        return $this->redirectToRoute('dashboard_area_modules', ['uuid' => $area->getUuidString()]);
    }

    #[Route('/reorder', name: 'dashboard_area_module_reorder', methods: ['POST'])]
    public function reorder(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $this->assertCsrf($request, 'module_reorder_'.$area->getUuidString());
        $order = array_values(array_filter(
            $request->request->all('order'),
            static fn (mixed $v): bool => \is_string($v) && Uuid::isValid($v),
        ));
        /** @var list<string> $order */
        $this->composition->reorder($area, $order);

        return $this->redirectToRoute('dashboard_area_modules', ['uuid' => $area->getUuidString()]);
    }

    private function assertCsrf(Request $request, string $id): void
    {
        $token = $request->request->get('_token');
        if (!\is_string($token) || !$this->isCsrfTokenValid($id, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
