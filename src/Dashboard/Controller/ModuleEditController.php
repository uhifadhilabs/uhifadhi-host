<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use App\Composition\Entity\AreaModule;
use App\Composition\Entity\Visualization;
use App\Composition\Enum\ModuleCategory;
use App\Composition\Enum\VizType;
use App\Composition\Repository\ModuleRepository;
use App\Composition\Repository\VisualizationRepository;
use App\Composition\Service\AreaCompositionService;
use App\Dashboard\Module\DatasetChartRenderer;
use App\Dashboard\Module\ModuleChartProvider;
use App\Spatial\Entity\AreaOfInterest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Edit mode for a module on an area: the module chip bar (reorder/remove/add) plus the module's
 * visualization grid (drag, configure, remove, add). Editing a module is the `module.create`
 * capability. The Forest-loss module's cards render the real Hansen charts — the same SVGs the
 * read-only page shows, wrapped in editable cards.
 */
#[Route('/areas/{uuid}/modules/{module}/edit', requirements: ['uuid' => Requirement::UUID, 'module' => '[a-z]+'])]
#[IsGranted('module.create')]
final class ModuleEditController extends AbstractController
{
    /**
     * @param iterable<ModuleChartProvider> $chartProviders
     */
    public function __construct(
        private readonly AreaCompositionService $composition,
        private readonly VisualizationRepository $visualizations,
        private readonly ModuleRepository $modules,
        private readonly EntityManagerInterface $em,
        private readonly DatasetChartRenderer $datasetCharts,
        #[AutowireIterator('app.module_chart_provider')]
        private readonly iterable $chartProviders,
    ) {
    }

    #[Route('', name: 'dashboard_area_module_edit', methods: ['GET'])]
    public function index(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $module,
        Request $request,
    ): Response {
        $areaModule = $this->areaModuleFor($area, $module);

        // ?configure=<uuid> overlays the "configure visualization" modal on this page.
        $configureViz = null;
        $configureUuid = $request->query->get('configure');
        if (\is_string($configureUuid) && Uuid::isValid($configureUuid)) {
            $configureViz = $this->visualizations->findOneBy(['uuid' => Uuid::fromString($configureUuid)]);
            if ($configureViz?->getAreaModule()?->getId() !== $areaModule->getId()) {
                $configureViz = null;
            }
        }

        return $this->render('dashboard/module_edit.html.twig', [
            'area' => $area,
            'current' => $areaModule,
            'active' => $this->composition->activeFor($area),
            'visualizations' => $areaModule->getVisualizations(),
            // Each visualization rendered by the module's provider (keyed by viz uuid); a viz the
            // provider can't draw yet is absent → the template shows a scaffold.
            'rendered' => $this->renderCharts($area, $module, $areaModule),
            'configureViz' => $configureViz,
            // The add-module modal (?addmodule): the catalogue grouped by category + counts.
            'addModule' => $request->query->getBoolean('addmodule'),
            'catalogueByCategory' => $this->catalogueByCategory($area),
            'categoryCounts' => $this->categoryCounts(),
        ]);
    }

    #[Route('/add-module', name: 'dashboard_area_module_addmodule', methods: ['POST'])]
    public function addModule(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $module,
        Request $request,
    ): Response {
        $this->assertCsrf($request, 'addmodule_'.$area->getUuidString());
        $slug = $request->request->get('module');
        $toAdd = \is_string($slug) ? $this->modules->findBySlug($slug) : null;
        if (null !== $toAdd) {
            $this->composition->addToArea($area, $toAdd);
        }

        // Back to the edit page with the modal still open, so more can be added.
        return $this->redirectToRoute('dashboard_area_module_edit', ['uuid' => $area->getUuidString(), 'module' => $module, 'addmodule' => 1]);
    }

    /**
     * The catalogue grouped by category (excluding the Overview hub), each with its on-this-area flag.
     *
     * @return array<string, list<array{module: \App\Composition\Entity\Module, active: bool}>>
     */
    private function catalogueByCategory(AreaOfInterest $area): array
    {
        $grouped = [];
        foreach ($this->composition->catalogueFor($area) as $row) {
            if (ModuleCategory::Hub === $row['module']->getCategory()) {
                continue;
            }
            $grouped[$row['module']->getCategory()->label()][] = $row;
        }

        return $grouped;
    }

    /**
     * @return array<string, int>
     */
    private function categoryCounts(): array
    {
        $counts = ['All' => 0];
        foreach ($this->modules->catalogue() as $module) {
            if (ModuleCategory::Hub === $module->getCategory()) {
                continue;
            }
            ++$counts['All'];
            $counts[$module->getCategory()->label()] = ($counts[$module->getCategory()->label()] ?? 0) + 1;
        }

        return $counts;
    }

    #[Route('/viz/{vizUuid}/update', name: 'dashboard_area_viz_update', requirements: ['vizUuid' => Requirement::UUID], methods: ['POST'])]
    public function vizUpdate(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $module,
        string $vizUuid,
        Request $request,
    ): Response {
        $this->assertCsrf($request, 'viz_update_'.$vizUuid);
        $viz = $this->visualizations->findOneBy(['uuid' => Uuid::fromString($vizUuid)]);
        if (null !== $viz && $viz->getAreaModule()?->getArea()?->getId() === $area->getId()) {
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

        return $this->redirectToRoute('dashboard_area_module_edit', ['uuid' => $area->getUuidString(), 'module' => $module]);
    }

    /**
     * Render each of the module's visualizations via its provider, keyed by viz uuid.
     *
     * @return array<string, string>
     */
    private function renderCharts(AreaOfInterest $area, string $module, AreaModule $areaModule): array
    {
        $provider = null;
        foreach ($this->chartProviders as $candidate) {
            if ($candidate->slug() === $module) {
                $provider = $candidate;
                break;
            }
        }

        $rendered = [];
        foreach ($areaModule->getVisualizations() as $viz) {
            // A viz bound to a dataset renders through the generic engine; a module's own canonical
            // charts (still unbound) fall back to its provider. Either may decline → the card scaffolds.
            $svg = $this->datasetCharts->render($area, $viz) ?? $provider?->render($area, $viz);
            if (null !== $svg) {
                $rendered[(string) $viz->getUuidString()] = $svg;
            }
        }

        return $rendered;
    }

    #[Route('/viz/add', name: 'dashboard_area_viz_add', methods: ['POST'])]
    public function vizAdd(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $module,
        Request $request,
    ): Response {
        $areaModule = $this->areaModuleFor($area, $module);
        $this->assertCsrf($request, 'viz_add_'.$areaModule->getUuidString());

        $type = VizType::tryFrom((string) $request->request->get('type', 'scatter')) ?? VizType::Scatter;
        $viz = (new Visualization())
            ->setAreaModule($areaModule)
            ->setTitle($this->titleFrom($request, $type))
            ->setType($type)
            ->setXAxis((string) $request->request->get('xAxis', 'Year'))
            ->setYAxis((string) $request->request->get('yAxis', 'Loss (ha)'))
            ->setColourBy($this->colourFrom($request))
            ->setAggregation('None')
            ->setPosition($areaModule->getVisualizations()->count());

        $this->em->persist($viz);
        $this->em->flush();
        $this->addFlash('success', 'Visualization added.');

        return $this->redirectToRoute('dashboard_area_module_edit', ['uuid' => $area->getUuidString(), 'module' => $module]);
    }

    #[Route('/viz/{vizUuid}/delete', name: 'dashboard_area_viz_delete', requirements: ['vizUuid' => Requirement::UUID], methods: ['POST'])]
    public function vizDelete(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $module,
        string $vizUuid,
        Request $request,
    ): Response {
        $this->assertCsrf($request, 'viz_delete_'.$vizUuid);
        $viz = $this->visualizations->findOneBy(['uuid' => Uuid::fromString($vizUuid)]);
        if (null !== $viz && $viz->getAreaModule()?->getArea()?->getId() === $area->getId()) {
            $this->em->remove($viz);
            $this->em->flush();
        }

        return $this->redirectToRoute('dashboard_area_module_edit', ['uuid' => $area->getUuidString(), 'module' => $module]);
    }

    #[Route('/viz/reorder', name: 'dashboard_area_viz_reorder', methods: ['POST'])]
    public function vizReorder(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $module,
        Request $request,
    ): Response {
        $areaModule = $this->areaModuleFor($area, $module);
        $this->assertCsrf($request, 'viz_reorder_'.$areaModule->getUuidString());

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

        return $this->redirectToRoute('dashboard_area_module_edit', ['uuid' => $area->getUuidString(), 'module' => $module]);
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

    private function titleFrom(Request $request, VizType $type): string
    {
        $title = $request->request->get('title');
        if (\is_string($title) && '' !== trim($title)) {
            return $title;
        }

        return \sprintf('%s vs %s', (string) $request->request->get('yAxis', 'Loss (ha)'), (string) $request->request->get('xAxis', 'Year'));
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
