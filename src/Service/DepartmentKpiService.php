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

namespace Uhifadhi\Service;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Uhifadhi\Entity\Department;
use Uhifadhi\Entity\DepartmentGoal;
use Uhifadhi\Module\DepartmentKpi;
use Uhifadhi\Module\DepartmentKpiProviderInterface;

/**
 * The department KPI aggregator: every tagged module provider, asked once per department, in one
 * list the performance surfaces render.
 *
 * It holds no arithmetic of its own on purpose. A department's numbers ARE its attached modules'
 * numbers, so the only judgement this service makes is WHICH providers to ask — and that
 * judgement is one line: the module must be attached. Everything else (the position slice, the
 * area sum, the period) belongs to the module that owns the rows.
 *
 * Consequences worth stating, because a reader will look for them and not find them:
 *
 *  - There is no fallback, no proxy and no estimate. A department with no attached module gets
 *    an empty list, and the page draws dashed labelled slots.
 *  - Nothing here filters by permission. A department is a lens, never a fence; this service
 *    reads and never gates, and everyone who may open a performance page may open all of it.
 */
final readonly class DepartmentKpiService
{
    /**
     * @param iterable<DepartmentKpiProviderInterface> $providers every installed module that
     *                                                            computes department figures
     */
    public function __construct(
        #[AutowireIterator(DepartmentKpiProviderInterface::TAG)]
        private iterable $providers,
    ) {
    }

    /**
     * This department's figures, from the modules it attaches, in provider order.
     *
     * @return list<DepartmentKpi>
     */
    public function forDepartment(Department $department, \DateTimeImmutable $now): array
    {
        $attached = self::attachedSlugs($department);

        $kpis = [];
        foreach ($this->providers as $provider) {
            if (!\in_array($provider->moduleSlug(), $attached, true)) {
                continue;
            }
            foreach ($provider->kpisFor($department, $now) as $kpi) {
                $kpis[] = $kpi;
            }
        }

        return $kpis;
    }

    /**
     * The same, for a whole board. Every department asked about gets a key — an empty department
     * is a real answer and exactly what the board is honest about — so no template guards on a
     * missing key.
     *
     * Asked ONCE PER DEPARTMENT rather than once per module: two departments sharing a module
     * must get two different numbers from the same rows, because the split is by the recording
     * person's position. A single call could not tell them apart.
     *
     * @param list<Department> $departments
     *
     * @return array<int, list<DepartmentKpi>> keyed by department id
     */
    public function forDepartments(array $departments, \DateTimeImmutable $now): array
    {
        $byDepartment = [];
        foreach ($departments as $department) {
            $id = $department->getId();
            if (null === $id) {
                continue;
            }
            $byDepartment[$id] = $this->forDepartment($department, $now);
        }

        return $byDepartment;
    }

    /**
     * The board's columns: every KPI key any department reported, in first-seen order, each
     * carrying the label and unit its module gave it.
     *
     * Derived rather than declared, so installing a module grows the board and removing one
     * shrinks it, with no list to keep in step.
     *
     * @param array<int, list<DepartmentKpi>> $byDepartment
     *
     * @return list<array{key: string, label: string, unit: string, moduleName: string}>
     */
    public static function columns(array $byDepartment): array
    {
        $columns = [];
        foreach ($byDepartment as $kpis) {
            foreach (self::totals($kpis) as $kpi) {
                $columns[$kpi->key] ??= [
                    'key' => $kpi->key,
                    'label' => $kpi->label,
                    'unit' => $kpi->unit,
                    'moduleName' => $kpi->moduleName,
                ];
            }
        }

        return array_values($columns);
    }

    /**
     * One department's figure for one column, or NULL — which the board draws as a dashed
     * labelled slot and never as a zero.
     *
     * @param list<DepartmentKpi> $kpis
     */
    public static function cell(array $kpis, string $key): ?DepartmentKpi
    {
        foreach (self::totals($kpis) as $kpi) {
            if ($kpi->key === $key) {
                return $kpi;
            }
        }

        return null;
    }

    /**
     * The department TOTALS only — what every headline plate, board cell and goal is scored
     * from. Per-area figures live in the same list and would double-count if a caller forgot
     * them, so no caller is asked to remember: they are filtered out here, once.
     *
     * @param list<DepartmentKpi> $kpis
     *
     * @return list<DepartmentKpi>
     */
    public static function totals(array $kpis): array
    {
        return array_values(array_filter($kpis, static fn (DepartmentKpi $kpi): bool => $kpi->isTotal()));
    }

    /**
     * The same figures split by the areas the department's modules run in, keyed by area name,
     * in first-seen order.
     *
     * Empty is a real answer and an honest one: a module that cannot split its figure by area
     * returns totals only, and the per-area widget says so rather than dividing a total by the
     * number of areas and calling the result data.
     *
     * @param list<DepartmentKpi> $kpis
     *
     * @return array<string, list<DepartmentKpi>>
     */
    public static function perArea(array $kpis): array
    {
        $byArea = [];
        foreach ($kpis as $kpi) {
            if (null === $kpi->areaName) {
                continue;
            }
            $byArea[$kpi->areaName][] = $kpi;
        }

        return $byArea;
    }

    /**
     * Each declared goal beside the figure it is scored from, already resolved.
     *
     * Done HERE rather than in Twig because pairing a goal with a KPI is a LOOKUP, and a template
     * that can look one figure up can look the wrong one up. It also means "awaiting module" is
     * decided once, in the one place that knows what the modules reported, rather than by every
     * widget that draws a goal.
     *
     * @param list<DepartmentGoal> $goals
     * @param list<DepartmentKpi>  $kpis
     *
     * @return list<array{goal: DepartmentGoal, kpi: DepartmentKpi|null, state: string, label: string, attainment: float|null}>
     */
    public static function score(array $goals, array $kpis): array
    {
        $scored = [];
        foreach ($goals as $goal) {
            // A goal names a KPI KEY, not a module: the module that answers it may change, and
            // a goal declared before any module measures it is legitimately unmeasurable.
            $kpi = self::cell($kpis, (string) $goal->getKpiRef());
            $scored[] = [
                'goal' => $goal,
                'kpi' => $kpi,
                'state' => $goal->state($kpi),
                'label' => $goal->stateLabel($kpi),
                'attainment' => $goal->attainment($kpi),
            ];
        }

        return $scored;
    }

    /**
     * The modules this department attaches, by slug.
     *
     * @return list<string>
     */
    private static function attachedSlugs(Department $department): array
    {
        $slugs = [];
        foreach ($department->getModules() as $module) {
            $slug = $module->getSlug();
            if (null !== $slug) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }
}
