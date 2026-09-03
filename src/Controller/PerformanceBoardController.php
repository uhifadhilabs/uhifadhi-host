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

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Entity\Department;
use Uhifadhi\Model\PerformanceBoardView;
use Uhifadhi\Model\PerformanceBoardWidgets;
use Uhifadhi\Model\WidgetDom;
use Uhifadhi\Module\DepartmentKpi;
use Uhifadhi\Repository\AreaOfInterestRepository;
use Uhifadhi\Repository\DepartmentGoalRepository;
use Uhifadhi\Seam\Repository\AreaModuleRepository;
use Uhifadhi\Service\DepartmentKpiService;
use Uhifadhi\Service\DepartmentsSurface;
use Uhifadhi\Service\WidgetEndpoint;
use Uhifadhi\Service\WidgetService;

/**
 * The org-wide comparative board: `GET /departments/performance`.
 *
 * Every department is a row — INCLUDING the ones with nothing to show. That is the whole point of
 * the screen and the thing it would be easiest to get wrong: a department whose modules report no
 * KPI is drawn with dashed labelled cells, never with zeros, and is never hidden. A zero says
 * "they did nothing"; a dash says "nothing here measures that", and only one of those is true.
 *
 * The COLUMNS are not declared anywhere. They are whatever KPIs the installed modules reported
 * for at least one department ({@see DepartmentKpiService::columns()}), so installing a module
 * grows the board and detaching one shrinks it, with no list to keep in step.
 *
 * THE BOARD IS NOT A LEAGUE TABLE, and the template says so in the design's own words. The rank
 * tint is a department's standing WITHIN ITS COLUMN — a department with fewer people, fewer areas
 * or one module is smaller, not worse.
 *
 * And it fences nothing. Selecting a department narrows what is SHOWN, never what is permitted:
 * everyone who can open this board can open every department on it, because a department is a
 * lens and never a gate.
 */
#[Route('/departments/performance')]
final class PerformanceBoardController extends AbstractController
{
    public function __construct(
        private readonly DepartmentsSurface $surface,
        private readonly AreaOfInterestRepository $areas,
        private readonly AreaModuleRepository $areaModules,
        private readonly DepartmentGoalRepository $goals,
        private readonly DepartmentKpiService $kpis,
        private readonly WidgetService $widgets,
        private readonly WidgetEndpoint $widgetEndpoint,
    ) {
    }

    #[Route('', name: 'app_departments_performance', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->render('departments/performance.html.twig', [
            ...$this->context($request),
            'widgets' => $this->widgets->resolve(PerformanceBoardWidgets::catalog(), $this->userId()),
        ]);
    }

    /**
     * THE SAME VIEW MODEL, laid out to be printed — the design's "Export board".
     *
     * It is the board itself and not a second rendering of it: the same context, the same widget
     * partials, the same period. Only the page chrome steps aside (see the `@media print` block in
     * app.css), because an exported board that disagreed with the screen would be worse than none.
     * A server-side PDF renderer is not installed; the browser's own is what turns this into one.
     */
    #[Route('/export', name: 'app_departments_performance_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        return $this->render('departments/performance.html.twig', [
            ...$this->context($request),
            'widgets' => $this->widgets->resolve(PerformanceBoardWidgets::catalog(), $this->userId()),
            'print' => true,
        ]);
    }

    #[Route('/widgets', name: 'app_departments_performance_widgets', methods: ['GET'])]
    public function widgetLibrary(): Response
    {
        $catalog = PerformanceBoardWidgets::catalog();
        $userId = $this->userId();

        return $this->render('departments/performance/library.html.twig', [
            ...$this->context(),
            // Everything templates/widgets/_library.html.twig is parameterised by — the shared
            // preset component, whole, over this surface's catalogue and this surface's routes.
            'catalog' => $catalog,
            'builtins' => $catalog->builtins(),
            'customPresets' => $this->widgets->customPresets($catalog, $userId),
            'active' => $this->widgets->activeRef($catalog, $userId),
            'widgets' => $this->widgets->resolve($catalog, $userId),
            'partial' => 'departments/performance/_b_%s.html.twig',
            'urls' => $this->widgetUrls(),
            'csrfToken' => $this->widgetEndpoint->csrfToken($catalog),
        ]);
    }

    #[Route('/widgets/save', name: 'app_departments_performance_widgets_save', methods: ['POST'])]
    public function widgetsSave(Request $request): Response
    {
        return $this->widgetEndpoint->save($request, PerformanceBoardWidgets::catalog());
    }

    #[Route('/widgets/reset', name: 'app_departments_performance_widgets_reset', methods: ['POST'])]
    public function widgetsReset(Request $request): Response
    {
        return $this->widgetEndpoint->reset($request, PerformanceBoardWidgets::catalog());
    }

    #[Route('/widgets/preset/{presetId}', name: 'app_departments_performance_widgets_preset', requirements: ['presetId' => '[a-z0-9_-]+'], methods: ['POST'])]
    public function widgetsPreset(Request $request, string $presetId): Response
    {
        $catalog = PerformanceBoardWidgets::catalog();

        return $this->afterPresetWrite(
            $this->widgetEndpoint->applyPreset($request, $catalog, $presetId),
            \sprintf('The board now follows “%s”.', $catalog->preset($presetId)?->label),
            'app_departments_performance',
        );
    }

    /**
     * MAKE A COPY TO CUSTOMIZE — the only door from one of the board's shipped designs into an
     * editable layout. The copy becomes active, so it lands back on the library to be edited.
     */
    #[Route('/widgets/preset/{presetId}/copy', name: 'app_departments_performance_widgets_preset_copy', requirements: ['presetId' => '[a-z0-9_-]+'], methods: ['POST'])]
    public function widgetsPresetCopy(Request $request, string $presetId): Response
    {
        return $this->afterPresetWrite(
            $this->widgetEndpoint->copyPreset($request, PerformanceBoardWidgets::catalog(), $presetId),
            'Copied. The copy is yours to edit, and your board is on it.',
            'app_departments_performance_widgets',
        );
    }

    #[Route('/widgets/presets', name: 'app_departments_performance_widgets_preset_create', methods: ['POST'])]
    public function widgetsPresetCreate(Request $request): Response
    {
        return $this->afterPresetWrite(
            $this->widgetEndpoint->createCustomPreset($request, PerformanceBoardWidgets::catalog()),
            'Saved. Your layout is in “My presets”.',
            'app_departments_performance_widgets',
        );
    }

    #[Route('/widgets/presets/{presetUuid}/apply', name: 'app_departments_performance_widgets_preset_apply', requirements: ['presetUuid' => Requirement::UUID], methods: ['POST'])]
    public function widgetsPresetApply(Request $request, string $presetUuid): Response
    {
        return $this->afterPresetWrite(
            $this->widgetEndpoint->applyCustomPreset($request, PerformanceBoardWidgets::catalog(), Uuid::fromString($presetUuid)),
            'The board now follows your saved preset.',
            'app_departments_performance',
        );
    }

    #[Route('/widgets/presets/{presetUuid}/rename', name: 'app_departments_performance_widgets_preset_rename', requirements: ['presetUuid' => Requirement::UUID], methods: ['POST'])]
    public function widgetsPresetRename(Request $request, string $presetUuid): Response
    {
        return $this->afterPresetWrite(
            $this->widgetEndpoint->renameCustomPreset($request, PerformanceBoardWidgets::catalog(), Uuid::fromString($presetUuid)),
            'Renamed.',
            'app_departments_performance_widgets',
        );
    }

    #[Route('/widgets/presets/{presetUuid}/delete', name: 'app_departments_performance_widgets_preset_delete', requirements: ['presetUuid' => Requirement::UUID], methods: ['POST'])]
    public function widgetsPresetDelete(Request $request, string $presetUuid): Response
    {
        return $this->afterPresetWrite(
            $this->widgetEndpoint->deleteCustomPreset($request, PerformanceBoardWidgets::catalog(), Uuid::fromString($presetUuid)),
            'Preset deleted. Your board is untouched.',
            'app_departments_performance_widgets',
        );
    }

    /**
     * THE LIBRARY'S WIRE, as URLs — the same map every surface hands the shared component. A
     * template carries {@see WidgetDom::ID_PLACEHOLDER} where the id goes.
     *
     * @return array<string, string>
     */
    private function widgetUrls(): array
    {
        $id = WidgetDom::ID_PLACEHOLDER;

        return [
            'save' => $this->generateUrl('app_departments_performance_widgets_save'),
            'reset' => $this->generateUrl('app_departments_performance_widgets_reset'),
            'preset' => $this->generateUrl('app_departments_performance_widgets_preset', ['presetId' => $id]),
            'copy' => $this->generateUrl('app_departments_performance_widgets_preset_copy', ['presetId' => $id]),
            'presets' => $this->generateUrl('app_departments_performance_widgets_preset_create'),
            'apply' => $this->generateUrl('app_departments_performance_widgets_preset_apply', ['presetUuid' => $id]),
            'rename' => $this->generateUrl('app_departments_performance_widgets_preset_rename', ['presetUuid' => $id]),
            'delete' => $this->generateUrl('app_departments_performance_widgets_preset_delete', ['presetUuid' => $id]),
            'dashboard' => $this->generateUrl('app_departments_performance'),
        ];
    }

    /**
     * The preset strip is plain forms, so a successful write answers the way every form on the
     * site does — a flash and a redirect. A refusal is returned exactly as the endpoint wrote it.
     */
    private function afterPresetWrite(Response $response, string $flash, string $route): Response
    {
        if (Response::HTTP_NO_CONTENT !== $response->getStatusCode()) {
            return $response;
        }

        $this->addFlash('success', $flash);

        return $this->redirectToRoute($route);
    }

    /**
     * EXACTLY what a `departments/performance/_b_*` partial receives.
     *
     * EVERY CONTROL ON THIS PAGE IS A QUERY PARAMETER — the period, the search, the bucket, the
     * sort and the selected department — and each is resolved HERE, so the board sorts, filters
     * and drills with no script at all. The design's own note says as much: its script "is what
     * that page does between navigations".
     *
     * The request is optional because the widget library renders these same partials to preview
     * them; a preview simply gets the page's defaults.
     *
     * @return array<string, mixed>
     */
    private function context(?Request $request = null): array
    {
        $query = $request?->query;
        $period = PerformanceBoardView::period(self::param($query, 'period'));
        $now = new \DateTimeImmutable();
        $window = PerformanceBoardView::window($period, $now);

        $surface = $this->surface->context();
        $departments = $surface['departments'];
        // The modules are asked about the instant the chosen window ends, or now if it has not.
        // A module still decides what its own period IS ({@see DepartmentKpiProviderInterface}),
        // so a week and a month currently read the same figures — the seam carries an instant and
        // not a span, and widening it is a change to the seam, not to this page.
        $byDepartment = $this->kpis->forDepartments($departments, min($window['end'], $now));
        $columns = DepartmentKpiService::columns($byDepartment);

        $rows = PerformanceBoardView::rows(
            $departments,
            $byDepartment,
            $columns,
            $this->areasByDepartment($departments),
            array_map(\count(...), $surface['positionsByDepartment']),
            array_map(\count(...), $surface['usersByDepartment']),
        );

        $search = trim(self::param($query, 'q') ?? '');
        $selected = self::select($rows, self::param($query, 'selected'));
        $onScreen = $selected['department'] ?? null;
        $bucket = PerformanceBoardView::bucket(self::param($query, 'show'));
        $sort = self::sortKey(self::param($query, 'sort'), $columns);
        $direction = 'desc' === self::param($query, 'dir') ? 'desc' : 'asc';
        $found = PerformanceBoardView::filter($rows, $search, $bucket);

        return [
            'departments' => $departments,
            'columns' => $columns,
            // Every department, in board order — what the counts, the coverage table and the
            // drill-in read, so a search never changes what the page says about the org.
            'rows' => $rows,
            // What the table draws: searched, bucketed and sorted.
            'visibleRows' => PerformanceBoardView::sort($found, $sort, $direction, $columns),
            'counts' => PerformanceBoardView::counts($found),
            'shifts' => PerformanceBoardView::shifts($rows, $columns),
            'selected' => $selected,
            'rankColumn' => $columns[PerformanceBoardView::rankIndex($columns)] ?? null,
            // The stance strip counts what is really there: the modules that PRODUCED a figure
            // (not the ones installed) and the areas the board's departments actually run in.
            'liveModules' => self::countDistinct(array_map(
                static fn (array $kpis): array => array_map(static fn (DepartmentKpi $kpi): string => $kpi->moduleSlug, $kpis),
                $byDepartment,
            )),
            'coveredAreas' => self::countDistinct(array_map(static fn (array $row): array => $row['areas'], $rows)),
            'departmentsByModule' => $surface['departmentsByModule'],
            'kpisByDepartment' => $byDepartment,
            'goalsByDepartment' => self::forPeriod(
                self::scoreAll($this->goals->forDepartments($departments), $byDepartment),
                $period,
            ),
            // The page's own state, as the query string that reproduces it — every link on the
            // board merges into this, so following one never silently drops the period.
            'query' => [
                'period' => $period,
                'q' => $search,
                'show' => $bucket,
                'sort' => $sort,
                'dir' => $direction,
                'selected' => $onScreen instanceof Department ? $onScreen->getUuidString() : '',
            ],
            'period' => $period,
            'periods' => PerformanceBoardView::PERIODS,
            'window' => $window,
            'now' => $now,
            'print' => false,
        ];
    }

    /**
     * The row the drill-in is about: the one asked for, or — when nothing was asked for, or a
     * stale link asks for a department that no longer exists — the first row with something to
     * show, and failing that the first row at all. The panel says which it is looking at either
     * way, so a defaulted selection is never mistaken for a chosen one.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>|null
     */
    private static function select(array $rows, ?string $uuid): ?array
    {
        foreach ($rows as $row) {
            $department = $row['department'];
            \assert($department instanceof Department);
            if (null !== $uuid && $department->getUuidString() === $uuid) {
                return $row;
            }
        }

        foreach ($rows as $row) {
            if (true === $row['reporting']) {
                return $row;
            }
        }

        return $rows[0] ?? null;
    }

    /**
     * The areas each department's modules are switched on in, by name.
     *
     * DERIVED, never tracked: the host knows area × module and a department knows which modules it
     * attaches, so a department's footprint is that intersection and nothing more. It is not a
     * measure of the department's work — the KPI seam is.
     *
     * @param list<Department> $departments
     *
     * @return array<int, list<string>>
     */
    private function areasByDepartment(array $departments): array
    {
        $byDepartment = [];
        $attached = [];
        foreach ($departments as $department) {
            $id = $department->getId();
            if (null === $id) {
                continue;
            }
            $byDepartment[$id] = [];
            foreach ($department->getModules() as $module) {
                $slug = $module->getSlug();
                if (null !== $slug) {
                    $attached[$id][$slug] = true;
                }
            }
        }

        // Once per area rather than once per department: the same handful of rows answers all of
        // them, and a board is every department at once.
        foreach ($this->areas->findAll() as $area) {
            $here = [];
            foreach ($this->areaModules->activeForArea($area) as $areaModule) {
                $slug = $areaModule->getModule()?->getSlug();
                if (null !== $slug) {
                    $here[$slug] = true;
                }
            }
            foreach ($byDepartment as $id => $names) {
                if ([] !== array_intersect_key($attached[$id] ?? [], $here)) {
                    $byDepartment[$id][] = (string) $area->getName();
                }
            }
        }

        return $byDepartment;
    }

    /**
     * A goal belongs to a period. The board is read over one, so it shows the goals declared for
     * that one — a monthly goal is not evidence about a year.
     *
     * @param array<int, list<array{goal: \Uhifadhi\Entity\DepartmentGoal, kpi: DepartmentKpi|null, state: string, label: string, attainment: float|null}>> $scored
     *
     * @return array<int, list<array{goal: \Uhifadhi\Entity\DepartmentGoal, kpi: DepartmentKpi|null, state: string, label: string, attainment: float|null}>>
     */
    private static function forPeriod(array $scored, string $period): array
    {
        foreach ($scored as $id => $entries) {
            $scored[$id] = array_values(array_filter(
                $entries,
                static fn (array $entry): bool => $entry['goal']->getPeriod() === $period,
            ));
        }

        return $scored;
    }

    /**
     * A sort key the board really has: a column the installed modules reported, the name, or the
     * rank. Anything else is the rank, silently — a URL is not a place to raise an error.
     *
     * @param list<array{key: string, label: string, unit: string, moduleName: string}> $columns
     */
    private static function sortKey(?string $requested, array $columns): string
    {
        $keys = ['name', 'rank', ...array_column($columns, 'key')];

        return \in_array($requested, $keys, true) ? $requested : 'rank';
    }

    /**
     * How many different things appear across a set of lists — how many modules really produced a
     * figure, how many areas the board really covers.
     *
     * @param array<int, list<string>> $lists
     */
    private static function countDistinct(array $lists): int
    {
        $seen = [];
        foreach ($lists as $list) {
            foreach ($list as $value) {
                $seen[$value] = true;
            }
        }

        return \count($seen);
    }

    /** @param InputBag<string>|null $query */
    private static function param(?InputBag $query, string $key): ?string
    {
        $value = $query?->get($key);

        return \is_string($value) ? $value : null;
    }

    /**
     * Every department's goals, each already paired with the figure it is scored from — the same
     * pairing the detail page does, through the same method, so a goal cannot read "met" on one
     * screen and "at risk" on the other.
     *
     * @param array<int, list<\Uhifadhi\Entity\DepartmentGoal>> $goalsByDepartment
     * @param array<int, list<DepartmentKpi>>                   $kpisByDepartment
     *
     * @return array<int, list<array{goal: \Uhifadhi\Entity\DepartmentGoal, kpi: DepartmentKpi|null, state: string, label: string, attainment: float|null}>>
     */
    private static function scoreAll(array $goalsByDepartment, array $kpisByDepartment): array
    {
        $scored = [];
        foreach ($goalsByDepartment as $id => $goals) {
            $scored[$id] = DepartmentKpiService::score($goals, $kpisByDepartment[$id] ?? []);
        }

        return $scored;
    }

    private function userId(): int
    {
        return $this->widgetEndpoint->userId();
    }
}
