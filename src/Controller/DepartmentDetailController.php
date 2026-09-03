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

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Entity\Department;
use Uhifadhi\Entity\DepartmentGoal;
use Uhifadhi\Entity\Position;
use Uhifadhi\Entity\User;
use Uhifadhi\Model\DepartmentDetailWidgets;
use Uhifadhi\Model\DepartmentPerformanceWidgets;
use Uhifadhi\Model\WidgetCatalog;
use Uhifadhi\Model\WidgetDom;
use Uhifadhi\Repository\AreaOfInterestRepository;
use Uhifadhi\Repository\DepartmentGoalRepository;
use Uhifadhi\Repository\DepartmentRepository;
use Uhifadhi\Repository\PositionRepository;
use Uhifadhi\Seam\Repository\AreaModuleRepository;
use Uhifadhi\Seam\Repository\ModuleRepository;
use Uhifadhi\Service\DepartmentKpiService;
use Uhifadhi\Service\DepartmentLens;
use Uhifadhi\Service\WidgetEndpoint;
use Uhifadhi\Service\WidgetService;

/**
 * ONE department, at its own address: `GET /departments/{uuid}`.
 *
 * FOUR TABS, ONE ROUTE (v1). The design draws Overview · Performance · People & Positions ·
 * Settings and notes that in the app each tab could be its own route. They are in-page panels
 * here instead, for one reason worth stating: two of the four are WIDGET SURFACES, and a widget
 * surface costs a library, a save endpoint, a reset and a preset strip each. Behind one route the
 * two surfaces are resolved together and the tab strip is `<a href="#performance">`, which the
 * design's own script already treats as navigation. Splitting the routes later changes this file
 * and no template contract.
 *
 * WHAT THE NUMBERS ARE. Nothing on the Performance tab is computed here. A department has no
 * figures of its own: every plate is an attached module's KPI, summed over the areas that module
 * runs in, sliced to the people whose position sits in this department
 * ({@see DepartmentKpiService}). A module that is not attached contributes NOTHING — not a zero —
 * and its slot is drawn dashed and labelled.
 *
 * WHOSE LAYOUT. Both widget surfaces store ONE LAYOUT PER PERSON, GLOBALLY: every framework call
 * below passes a null area, and none of them passes the department. A person arranges the
 * department record once and every department they open afterwards wears that arrangement — see
 * {@see DepartmentDetailWidgets} for why that is the right default.
 *
 * READING is for everyone signed in — a department is a lens, and a lens nobody may look through
 * explains nothing. WRITING (goals) is Manager-and-up, exactly as the org chart's other writes.
 */
#[Route('/departments/{uuid}', requirements: ['uuid' => Requirement::UUID])]
final class DepartmentDetailController extends AbstractController
{
    /** Shared with {@see DepartmentsController}: the org chart's writes are one capability. */
    private const string MANAGE_TOKEN = 'department_manage';

    public function __construct(
        private readonly DepartmentRepository $departments,
        private readonly DepartmentGoalRepository $goals,
        private readonly PositionRepository $positions,
        private readonly ModuleRepository $modules,
        private readonly AreaOfInterestRepository $areas,
        private readonly AreaModuleRepository $areaModules,
        private readonly DepartmentKpiService $kpis,
        private readonly DepartmentLens $lens,
        private readonly WidgetService $widgets,
        private readonly WidgetEndpoint $widgetEndpoint,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_department_show', methods: ['GET'])]
    public function show(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Department $department,
    ): Response {
        $detail = DepartmentDetailWidgets::catalog();
        $performance = DepartmentPerformanceWidgets::catalog();

        return $this->render('departments/detail/show.html.twig', [
            ...$this->context($department),
            'detailWidgets' => $this->widgets->resolve($detail, $this->userId()),
            'performanceWidgets' => $this->widgets->resolve($performance, $this->userId()),
        ]);
    }

    /**
     * The Overview tab's widget library. Every card is the REAL partial at full size, from the
     * same context the tab renders — so what you arrange here is exactly what you get, on this
     * department and on every other.
     */
    #[Route('/widgets', name: 'app_department_widgets', methods: ['GET'])]
    public function widgets(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Department $department,
    ): Response {
        return $this->library($department, DepartmentDetailWidgets::catalog(), 'overview');
    }

    #[Route('/performance/widgets', name: 'app_department_performance_widgets', methods: ['GET'])]
    public function performanceWidgets(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Department $department,
    ): Response {
        return $this->library($department, DepartmentPerformanceWidgets::catalog(), 'performance');
    }

    #[Route('/widgets/save', name: 'app_department_widgets_save', methods: ['POST'])]
    public function widgetsSave(Request $request): Response
    {
        return $this->widgetEndpoint->save($request, DepartmentDetailWidgets::catalog());
    }

    #[Route('/widgets/reset', name: 'app_department_widgets_reset', methods: ['POST'])]
    public function widgetsReset(Request $request): Response
    {
        return $this->widgetEndpoint->reset($request, DepartmentDetailWidgets::catalog());
    }

    #[Route('/performance/widgets/save', name: 'app_department_performance_widgets_save', methods: ['POST'])]
    public function performanceWidgetsSave(Request $request): Response
    {
        return $this->widgetEndpoint->save($request, DepartmentPerformanceWidgets::catalog());
    }

    #[Route('/performance/widgets/reset', name: 'app_department_performance_widgets_reset', methods: ['POST'])]
    public function performanceWidgetsReset(Request $request): Response
    {
        return $this->widgetEndpoint->reset($request, DepartmentPerformanceWidgets::catalog());
    }

    #[Route('/widgets/preset/{presetId}', name: 'app_department_widgets_preset', requirements: ['presetId' => '[a-z0-9_-]+'], methods: ['POST'])]
    public function widgetsPreset(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Department $department,
        Request $request,
        string $presetId,
    ): Response {
        $catalog = DepartmentDetailWidgets::catalog();

        return $this->afterPresetWrite(
            $this->widgetEndpoint->applyPreset($request, $catalog, $presetId),
            \sprintf('Every department record now follows “%s”.', $catalog->preset($presetId)?->label),
            'app_department_show',
            $department,
        );
    }

    #[Route('/performance/widgets/preset/{presetId}', name: 'app_department_performance_widgets_preset', requirements: ['presetId' => '[a-z0-9_-]+'], methods: ['POST'])]
    public function performanceWidgetsPreset(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Department $department,
        Request $request,
        string $presetId,
    ): Response {
        $catalog = DepartmentPerformanceWidgets::catalog();

        return $this->afterPresetWrite(
            $this->widgetEndpoint->applyPreset($request, $catalog, $presetId),
            \sprintf('Every department scorecard now follows “%s”.', $catalog->preset($presetId)?->label),
            'app_department_show',
            $department,
        );
    }

    /**
     * MAKE A COPY TO CUSTOMIZE — one per surface, and the only door from a shipped design into an
     * editable layout. Both land back on the library, because customizing is what you came to do.
     */
    #[Route('/widgets/preset/{presetId}/copy', name: 'app_department_widgets_preset_copy', requirements: ['presetId' => '[a-z0-9_-]+'], methods: ['POST'])]
    public function widgetsPresetCopy(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Department $department,
        Request $request,
        string $presetId,
    ): Response {
        return $this->afterPresetWrite(
            $this->widgetEndpoint->copyPreset($request, DepartmentDetailWidgets::catalog(), $presetId),
            'Copied. The copy is yours to edit, and every department record is on it.',
            'app_department_widgets',
            $department,
        );
    }

    #[Route('/performance/widgets/preset/{presetId}/copy', name: 'app_department_performance_widgets_preset_copy', requirements: ['presetId' => '[a-z0-9_-]+'], methods: ['POST'])]
    public function performanceWidgetsPresetCopy(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Department $department,
        Request $request,
        string $presetId,
    ): Response {
        return $this->afterPresetWrite(
            $this->widgetEndpoint->copyPreset($request, DepartmentPerformanceWidgets::catalog(), $presetId),
            'Copied. The copy is yours to edit, and every department scorecard is on it.',
            'app_department_performance_widgets',
            $department,
        );
    }

    #[Route('/widgets/presets', name: 'app_department_widgets_preset_create', methods: ['POST'])]
    public function widgetsPresetCreate(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Department $department,
        Request $request,
    ): Response {
        return $this->afterPresetWrite(
            $this->widgetEndpoint->createCustomPreset($request, DepartmentDetailWidgets::catalog()),
            'Saved. Your layout is in “My presets”.',
            'app_department_widgets',
            $department,
        );
    }

    #[Route('/performance/widgets/presets', name: 'app_department_performance_widgets_preset_create', methods: ['POST'])]
    public function performanceWidgetsPresetCreate(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Department $department,
        Request $request,
    ): Response {
        return $this->afterPresetWrite(
            $this->widgetEndpoint->createCustomPreset($request, DepartmentPerformanceWidgets::catalog()),
            'Saved. Your layout is in “My presets”.',
            'app_department_performance_widgets',
            $department,
        );
    }

    #[Route('/widgets/presets/{presetUuid}/apply', name: 'app_department_widgets_preset_apply', requirements: ['presetUuid' => Requirement::UUID], methods: ['POST'])]
    public function widgetsPresetApply(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Department $department,
        Request $request,
        string $presetUuid,
    ): Response {
        return $this->afterPresetWrite(
            $this->widgetEndpoint->applyCustomPreset($request, DepartmentDetailWidgets::catalog(), Uuid::fromString($presetUuid)),
            'Every department record now follows your saved preset.',
            'app_department_show',
            $department,
        );
    }

    #[Route('/performance/widgets/presets/{presetUuid}/apply', name: 'app_department_performance_widgets_preset_apply', requirements: ['presetUuid' => Requirement::UUID], methods: ['POST'])]
    public function performanceWidgetsPresetApply(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Department $department,
        Request $request,
        string $presetUuid,
    ): Response {
        return $this->afterPresetWrite(
            $this->widgetEndpoint->applyCustomPreset($request, DepartmentPerformanceWidgets::catalog(), Uuid::fromString($presetUuid)),
            'Every department scorecard now follows your saved preset.',
            'app_department_show',
            $department,
        );
    }

    #[Route('/widgets/presets/{presetUuid}/rename', name: 'app_department_widgets_preset_rename', requirements: ['presetUuid' => Requirement::UUID], methods: ['POST'])]
    public function widgetsPresetRename(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Department $department,
        Request $request,
        string $presetUuid,
    ): Response {
        return $this->afterPresetWrite(
            $this->widgetEndpoint->renameCustomPreset($request, DepartmentDetailWidgets::catalog(), Uuid::fromString($presetUuid)),
            'Renamed.',
            'app_department_widgets',
            $department,
        );
    }

    #[Route('/performance/widgets/presets/{presetUuid}/rename', name: 'app_department_performance_widgets_preset_rename', requirements: ['presetUuid' => Requirement::UUID], methods: ['POST'])]
    public function performanceWidgetsPresetRename(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Department $department,
        Request $request,
        string $presetUuid,
    ): Response {
        return $this->afterPresetWrite(
            $this->widgetEndpoint->renameCustomPreset($request, DepartmentPerformanceWidgets::catalog(), Uuid::fromString($presetUuid)),
            'Renamed.',
            'app_department_performance_widgets',
            $department,
        );
    }

    #[Route('/widgets/presets/{presetUuid}/delete', name: 'app_department_widgets_preset_delete', requirements: ['presetUuid' => Requirement::UUID], methods: ['POST'])]
    public function widgetsPresetDelete(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Department $department,
        Request $request,
        string $presetUuid,
    ): Response {
        return $this->afterPresetWrite(
            $this->widgetEndpoint->deleteCustomPreset($request, DepartmentDetailWidgets::catalog(), Uuid::fromString($presetUuid)),
            'Preset deleted. Your dashboard is untouched.',
            'app_department_widgets',
            $department,
        );
    }

    #[Route('/performance/widgets/presets/{presetUuid}/delete', name: 'app_department_performance_widgets_preset_delete', requirements: ['presetUuid' => Requirement::UUID], methods: ['POST'])]
    public function performanceWidgetsPresetDelete(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Department $department,
        Request $request,
        string $presetUuid,
    ): Response {
        return $this->afterPresetWrite(
            $this->widgetEndpoint->deleteCustomPreset($request, DepartmentPerformanceWidgets::catalog(), Uuid::fromString($presetUuid)),
            'Preset deleted. Your dashboard is untouched.',
            'app_department_performance_widgets',
            $department,
        );
    }

    /**
     * Declare a goal. The one thing on a performance surface the platform does NOT derive: a
     * target is a human statement, and no module could infer it.
     *
     * The KPI it names is stored as a plain string rather than validated against the installed
     * modules: a department may commit to something before the module that measures it ships, and
     * the goal then reads "awaiting module" — which is the honest answer and the one the design
     * asks for. Refusing it would make the roadmap undeclarable.
     */
    #[Route('/goals', name: 'app_department_goal_create', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function createGoal(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Department $department,
        Request $request,
    ): Response {
        $this->denyUnlessTokenValid($request);

        $statement = trim($request->request->getString('statement'));
        $kpiRef = trim($request->request->getString('kpiRef'));
        if ('' === $statement || '' === $kpiRef) {
            $this->addFlash('error', 'A goal needs a statement and the KPI it is scored from.');

            return $this->backToSettings($department);
        }

        $period = $request->request->getString('period', 'month');
        if (!\in_array($period, DepartmentGoal::PERIODS, true)) {
            $this->addFlash('error', 'That is not a reporting period.');

            return $this->backToSettings($department);
        }

        $goal = new DepartmentGoal()
            ->setDepartment($department)
            ->setStatement($statement)
            ->setKpiRef($kpiRef)
            ->setTargetValue((float) $request->request->get('targetValue', 0))
            ->setTargetUnit(trim($request->request->getString('targetUnit')))
            ->setPeriod($period)
            ->setOwningPosition($this->owningPosition($department, $request));

        $this->entityManager->persist($goal);
        $this->entityManager->flush();

        $this->addFlash('success', \sprintf('“%s” declared.', $statement));

        return $this->backToSettings($department);
    }

    /**
     * Withdraw a goal. Only the declaration goes: the module KPI it was scored from is a module's
     * row and is untouched, so nothing a department measured disappears with its target.
     */
    #[Route('/goals/{goalUuid}/delete', name: 'app_department_goal_delete', requirements: ['goalUuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function deleteGoal(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Department $department,
        Request $request,
        string $goalUuid,
    ): Response {
        $this->denyUnlessTokenValid($request);

        // Scoped to the department in the URL: a uuid from another department's page cannot be
        // withdrawn through this one's form.
        $goal = $this->goals->findOneOwned($department, Uuid::fromString($goalUuid));
        if (null === $goal) {
            throw $this->createNotFoundException('That department has no such goal.');
        }

        $this->entityManager->remove($goal);
        $this->entityManager->flush();

        $this->addFlash('success', 'Goal withdrawn. Every figure it was scored from is untouched.');

        return $this->backToSettings($department);
    }

    /**
     * EXACTLY what a `departments/detail/_w_*` or `_p_*` partial receives — the contract, stated
     * once, so a partial that reaches for anything else fails on the surface that owns it.
     *
     * Gathered here rather than per widget: a dozen partials render on one page and most want the
     * same joins. Per widget they would cost a query each, and a partial that quietly queries is
     * a partial nobody can move to another surface.
     *
     * @return array<string, mixed>
     */
    private function context(Department $department): array
    {
        $now = new \DateTimeImmutable();
        $kpis = $this->kpis->forDepartment($department, $now);
        $goals = $this->goals->forDepartment($department);

        $positions = $this->positions->findBy(['department' => $department], ['name' => 'ASC']);
        /** @var list<User> $members */
        $members = $this->entityManager->createQueryBuilder()
            ->select('u', 'p')
            ->from(User::class, 'u')
            ->join('u.position', 'p')
            ->where('p.department = :department')
            ->setParameter('department', $department)
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
            ->getQuery()
            ->getResult();

        $attached = array_values($department->getModules()->toArray());
        $catalogue = $this->modules->catalogue();

        return [
            'department' => $department,
            // The lens preview asks the REAL ordering service, through a real member, so the
            // preview cannot drift from the Modules tab it claims to be previewing. With nobody
            // placed here yet the service is handed a null user and answers the catalogue
            // untouched — which is exactly what an empty department's lens does.
            'lensPersona' => $members[0] ?? null,
            'lensOrder' => $this->lens->moduleOrderFor($members[0] ?? null, $catalogue),
            'canManage' => $this->isGranted('ROLE_MANAGER'),
            'positions' => $positions,
            'members' => $members,
            'modules' => $catalogue,
            // Which OTHER departments claim each attached module — the "shared" markers.
            'departmentsByModule' => $this->departments->departmentsByModule($attached),
            // Derived, never tracked: simply where this department's modules are switched on.
            'footprint' => $this->footprint($department),
            'kpis' => DepartmentKpiService::totals($kpis),
            'kpisByArea' => DepartmentKpiService::perArea($kpis),
            'goals' => DepartmentKpiService::score($goals, $kpis),
            'now' => $now,
        ];
    }

    /**
     * The areas this department's modules are switched on in, as `area name => list<Module>`.
     *
     * DERIVED, and the widget says so: the host knows area × module, and a department knows which
     * modules it attaches, so this is the intersection and nothing more. It is not a measure of
     * the department's work — the KPI seam is.
     *
     * @return array<string, list<\Uhifadhi\Seam\Entity\Module>>
     */
    private function footprint(Department $department): array
    {
        $attached = [];
        foreach ($department->getModules() as $module) {
            $slug = $module->getSlug();
            if (null !== $slug) {
                $attached[$slug] = $module;
            }
        }
        if ([] === $attached) {
            return [];
        }

        $footprint = [];
        foreach ($this->areas->findAll() as $area) {
            $here = [];
            foreach ($this->areaModules->activeForArea($area) as $areaModule) {
                $slug = $areaModule->getModule()?->getSlug();
                if (null !== $slug && isset($attached[$slug])) {
                    $here[] = $attached[$slug];
                }
            }
            if ([] !== $here) {
                $footprint[(string) $area->getName()] = $here;
            }
        }

        return $footprint;
    }

    /**
     * The library screen, for either of this record's two widget surfaces.
     *
     * ONE SCREEN FOR BOTH, and the shared preset component below it for both: `tab` chooses the
     * catalogue, the partial-name format and the route set, and nothing else differs. Everything
     * handed over is exactly what templates/widgets/_library.html.twig is parameterised by.
     */
    private function library(Department $department, WidgetCatalog $catalog, string $tab): Response
    {
        $userId = $this->userId();

        return $this->render('departments/detail/widgets.html.twig', [
            ...$this->context($department),
            'tab' => $tab,
            'catalog' => $catalog,
            'builtins' => $catalog->builtins(),
            'customPresets' => $this->widgets->customPresets($catalog, $userId),
            'active' => $this->widgets->activeRef($catalog, $userId),
            'widgets' => $this->widgets->resolve($catalog, $userId),
            // Two surfaces, one library screen: the partial's name is the only thing that differs.
            'partial' => 'overview' === $tab
                ? 'departments/detail/_w_%s.html.twig'
                : 'departments/detail/_p_%s.html.twig',
            'urls' => $this->widgetUrls($department, $tab),
            'csrfToken' => $this->widgetEndpoint->csrfToken($catalog),
        ]);
    }

    /**
     * THE LIBRARY'S WIRE, as URLs, for one of this record's two surfaces.
     *
     * The DEPARTMENT is in every URL and in NONE of the stored rows: the routes are reached
     * through the department you are looking at (which is what makes "Back to Ecology" possible),
     * while the layout they write is one per person for every department — see the class docblock.
     * A template carries {@see WidgetDom::ID_PLACEHOLDER} where the id goes.
     *
     * @return array<string, string>
     */
    private function widgetUrls(Department $department, string $tab): array
    {
        $id = WidgetDom::ID_PLACEHOLDER;
        $uuid = ['uuid' => (string) $department->getUuidString()];
        $prefix = 'overview' === $tab ? 'app_department_widgets' : 'app_department_performance_widgets';

        return [
            'save' => $this->generateUrl($prefix.'_save', $uuid),
            'reset' => $this->generateUrl($prefix.'_reset', $uuid),
            'preset' => $this->generateUrl($prefix.'_preset', [...$uuid, 'presetId' => $id]),
            'copy' => $this->generateUrl($prefix.'_preset_copy', [...$uuid, 'presetId' => $id]),
            'presets' => $this->generateUrl($prefix.'_preset_create', $uuid),
            'apply' => $this->generateUrl($prefix.'_preset_apply', [...$uuid, 'presetUuid' => $id]),
            'rename' => $this->generateUrl($prefix.'_preset_rename', [...$uuid, 'presetUuid' => $id]),
            'delete' => $this->generateUrl($prefix.'_preset_delete', [...$uuid, 'presetUuid' => $id]),
            'dashboard' => $this->generateUrl('app_department_show', $uuid),
        ];
    }

    /** The position accountable for a goal, or null — it is optional, and SET NULL on delete. */
    private function owningPosition(Department $department, Request $request): ?Position
    {
        $uuid = trim($request->request->getString('owningPosition'));
        if ('' === $uuid || !Uuid::isValid($uuid)) {
            return null;
        }

        $position = $this->positions->findOneBy(['uuid' => Uuid::fromString($uuid)]);

        // A position belongs to ONE department; owning another department's is a category error,
        // not a permission question, so it is simply dropped.
        return $position?->getDepartment() === $department ? $position : null;
    }

    private function backToSettings(Department $department): Response
    {
        return $this->redirect($this->generateUrl('app_department_show', ['uuid' => (string) $department->getUuidString()]).'#settings');
    }

    /** As in {@see DepartmentsController}: a refusal is returned as written, never re-invented. */
    private function afterPresetWrite(Response $response, string $flash, string $route, Department $department): Response
    {
        if (Response::HTTP_NO_CONTENT !== $response->getStatusCode()) {
            return $response;
        }

        $this->addFlash('success', $flash);

        return $this->redirectToRoute($route, ['uuid' => (string) $department->getUuidString()]);
    }

    private function userId(): int
    {
        return $this->widgetEndpoint->userId();
    }

    private function denyUnlessTokenValid(Request $request): void
    {
        $token = $request->request->get('_token');
        if (!\is_string($token) || !$this->isCsrfTokenValid(self::MANAGE_TOKEN, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
