<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use App\Composition\Entity\AreaModule;
use App\Composition\Entity\Visualization;
use App\Composition\Enum\VizType;
use App\Composition\Repository\VisualizationRepository;
use App\Composition\Service\AreaCompositionService;
use App\Spatial\Entity\AreaOfInterest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * The visualization mutations of a module's Settings tab — add / configure / remove / reorder. These
 * are the Settings page's POST actions (they have no page of their own): each saves and returns to the
 * module's Settings tab, where the visualization list and the configure modal live. Editing a module's
 * charts is the `module.create` capability.
 */
#[Route('/areas/{uuid}/{module}/settings', requirements: ['uuid' => Requirement::UUID, 'module' => '[a-z]+'])]
#[IsGranted('module.create')]
final class ModuleEditController extends AbstractController
{
    public function __construct(
        private readonly AreaCompositionService $composition,
        private readonly VisualizationRepository $visualizations,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/viz/add', name: 'dashboard_area_viz_add', methods: ['POST'])]
    public function vizAdd(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $module,
        Request $request,
    ): Response {
        $this->assertCsrf($request, 'viz_add_'.$module);
        $areaModule = $this->areaModuleFor($area, $module);

        $type = VizType::tryFrom((string) $request->request->get('type', 'bar')) ?? VizType::Bar;
        $viz = (new Visualization())
            ->setAreaModule($areaModule)
            ->setTitle('New visualization')
            ->setType($type)
            ->setXAxis((string) $request->request->get('xAxis', ''))
            ->setYAxis((string) $request->request->get('yAxis', ''))
            ->setColourBy(null)
            ->setAggregation('None')
            ->setPosition($areaModule->getVisualizations()->count());

        $this->em->persist($viz);
        $this->em->flush();
        $this->addFlash('success', 'Visualization added — configure it below.');

        // Land back on Settings with the new viz's configure modal open, ready to bind.
        return $this->toSettings($area, $module, $viz->getUuidString());
    }

    #[Route('/viz/{vizUuid}/update', name: 'dashboard_area_viz_update', requirements: ['vizUuid' => Requirement::UUID], methods: ['POST'])]
    public function vizUpdate(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $module,
        string $vizUuid,
        Request $request,
    ): Response {
        $this->assertCsrf($request, 'viz_update_'.$vizUuid);
        $viz = $this->ownedViz($area, $vizUuid);
        if (null !== $viz) {
            $title = $request->request->get('title');
            $viz->setTitle(\is_string($title) && '' !== trim($title) ? $title : (string) $viz->getTitle())
                ->setType(VizType::tryFrom((string) $request->request->get('type', $viz->getType()->value)) ?? $viz->getType())
                ->setXAxis((string) $request->request->get('xAxis', (string) $viz->getXAxis()))
                ->setYAxis((string) $request->request->get('yAxis', (string) $viz->getYAxis()))
                ->setColourBy($this->colourFrom($request))
                ->setAggregation((string) $request->request->get('aggregation', $viz->getAggregation()));
            $this->em->flush();
            $this->addFlash('success', 'Visualization updated.');
        }

        return $this->toSettings($area, $module);
    }

    #[Route('/viz/{vizUuid}/delete', name: 'dashboard_area_viz_delete', requirements: ['vizUuid' => Requirement::UUID], methods: ['POST'])]
    public function vizDelete(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $module,
        string $vizUuid,
        Request $request,
    ): Response {
        $this->assertCsrf($request, 'viz_delete_'.$vizUuid);
        $viz = $this->ownedViz($area, $vizUuid);
        if (null !== $viz) {
            $this->em->remove($viz);
            $this->em->flush();
        }

        return $this->toSettings($area, $module);
    }

    #[Route('/viz/reorder', name: 'dashboard_area_viz_reorder', methods: ['POST'])]
    public function vizReorder(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $module,
        Request $request,
    ): Response {
        $this->assertCsrf($request, 'viz_reorder_'.$module);
        $areaModule = $this->areaModuleFor($area, $module);

        $rank = [];
        foreach ($request->request->all('order') as $position => $uuid) {
            if (\is_string($uuid)) {
                $rank[$uuid] = $position;
            }
        }
        foreach ($areaModule->getVisualizations() as $viz) {
            if (isset($rank[(string) $viz->getUuidString()])) {
                $viz->setPosition($rank[(string) $viz->getUuidString()]);
            }
        }
        $this->em->flush();

        return $this->toSettings($area, $module);
    }

    private function toSettings(AreaOfInterest $area, string $module, ?string $configure = null): Response
    {
        $params = ['uuid' => $area->getUuidString(), 'module' => $module, 'tab' => 'settings'];
        if (null !== $configure) {
            $params['configure'] = $configure;
        }

        return $this->redirectToRoute('dashboard_area_module', $params);
    }

    private function areaModuleFor(AreaOfInterest $area, string $module): AreaModule
    {
        foreach ($this->composition->activeFor($area) as $areaModule) {
            if ($areaModule->getModule()?->getSlug() === $module) {
                return $areaModule;
            }
        }

        throw $this->createNotFoundException(\sprintf('Module "%s" is not active on this area.', $module));
    }

    private function ownedViz(AreaOfInterest $area, string $vizUuid): ?Visualization
    {
        $viz = $this->visualizations->findOneBy(['uuid' => Uuid::fromString($vizUuid)]);

        return null !== $viz && $viz->getAreaModule()?->getArea()?->getId() === $area->getId() ? $viz : null;
    }

    private function colourFrom(Request $request): ?string
    {
        $colour = $request->request->get('colourBy');

        return \is_string($colour) && '' !== $colour && '— none —' !== $colour ? $colour : null;
    }

    private function assertCsrf(Request $request, string $id): void
    {
        $token = $request->request->get('_token');
        if (!\is_string($token) || !$this->isCsrfTokenValid($id, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
