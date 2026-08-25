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

namespace Uhifadhi\Model;

use Uhifadhi\Module\DepartmentKpiProviderInterface;

/**
 * THE catalogue of ONE department's Performance tab — `GET /departments/{uuid}#performance`.
 *
 * Ported from the settled design (departments/ecology/performance.widgets.js) with ONE
 * deliberate generalisation, which is the only place this catalogue and that file differ:
 *
 * THE DESIGN DECLARES FOUR KPI WIDGETS; THIS SHIPS ONE.
 * The design names `kpi-patrols`, `kpi-distance`, `kpi-coverage` and `kpi-observations` — the
 * four figures the Patrols module happens to compute. A catalogue is HOST code, and the host must
 * not know what a module measures: naming a widget after another module's KPI would mean editing
 * this file every time a module ships, which is exactly the Open/Closed rule modules are held to.
 * So the four plates are one `scorecard` widget that draws ONE PLATE PER KPI THE DEPARTMENT'S
 * ATTACHED MODULES REPORTED ({@see DepartmentKpiProviderInterface}). On Ecology today that
 * renders precisely the design's four plates; install a module and it renders five, with no line
 * of host code changed. The same reasoning collapses the design's per-goal cards into the one
 * `goals` rail, which draws a card per declared goal.
 *
 * ONE LAYOUT, EVERY DEPARTMENT — as on the Overview tab, and for the same reason: the stored row
 * passes a NULL AREA, so a person arranges the scorecard once and every department they open
 * wears that arrangement. The uuid in the URL changes the figures, never their arrangement.
 */
final class DepartmentPerformanceWidgets
{
    /** What a stored preference row is keyed by — not keyed by the department; see the docblock. */
    public const string SURFACE = 'department-performance';

    public static function catalog(): WidgetCatalog
    {
        return new WidgetCatalog(
            self::SURFACE,
            [
                new WidgetGroup('headline', 'The month at a glance', 'Where the numbers came from, how the goals stand, and the figures the modules produced.'),
                new WidgetGroup('goals', 'Commitments', 'Goals a position declared, one card each, and the evidence underneath them.'),
                new WidgetGroup('areas', 'Per area', 'The same month split by the areas the department’s modules run in.'),
                new WidgetGroup('brief', 'The written brief', 'The month as prose, for someone who will read it and not click it.'),
            ],
            // Declaration order IS the default layout — the design's "Scorecard" direction.
            [
                new Widget('stance', 'Department header · period and goal stance', 'headline', 12, [12, 9], note: 'Who this department is, the period, and how its goals stand today.'),
                new Widget('provenance', 'Where these numbers come from', 'headline', 12, [12, 9], note: 'One paragraph on why a department has no numbers of its own.'),
                new Widget('scorecard', 'The module figures', 'headline', 12, [12, 9, 6], note: 'One plate per KPI the attached modules reported, with its month-over-month move and a sparkline.'),
                new Widget('goals', 'Goals', 'goals', 9, [12, 9, 6], note: 'Every declared goal as one grid: bar, target, owning position, state.'),
                new Widget('exceptions', 'Exceptions', 'headline', 3, [12, 9, 6, 3], note: 'The short list an exec should actually act on this month.'),
                new Widget('contribution', 'What produced these numbers', 'headline', 3, [12, 9, 6, 3], note: 'Which module fed which KPI, and in how many areas.'),
                new Widget('areas', 'Per-area breakdown', 'areas', 12, [12, 9, 6], note: 'Every figure split by the areas the department’s modules run in.'),
                new Widget('brief', 'Monthly brief', 'brief', 12, [12, 9], on: false, note: 'The month as one written paragraph and five numbers, for someone who will not click.'),
                new Widget('goal-declare', 'Declare a goal', 'goals', 3, [12, 6, 3], on: false, note: 'The form that declares a goal against one module KPI and one owning position.'),
            ],
            [
                new WidgetPreset(
                    'scorecard',
                    'Scorecard',
                    'Big KPI plates with the month-over-month move and a sparkline each, a goals rail beside the exceptions an exec should actually look at.',
                    ['stance' => 12, 'provenance' => 12, 'scorecard' => 12, 'goals' => 9, 'exceptions' => 3, 'contribution' => 3, 'areas' => 12],
                ),
                new WidgetPreset(
                    'goalsfirst',
                    'Goals first',
                    'The only direction where an exec reads commitments rather than telemetry, and goals awaiting a module stay honestly empty; useless for a department that has not declared any goals yet.',
                    ['stance' => 12, 'goals' => 12, 'goal-declare' => 3, 'scorecard' => 9, 'provenance' => 12],
                ),
                new WidgetPreset(
                    'byarea',
                    'By area',
                    'Answers “where did this happen?” before “how much of it was there?”; a department whose modules run in one area reads as a single row.',
                    ['stance' => 12, 'areas' => 12, 'scorecard' => 12, 'contribution' => 6, 'exceptions' => 6, 'provenance' => 12],
                ),
                new WidgetPreset(
                    'brief',
                    'Written brief',
                    'The month as prose for someone who will read it and not click it; the slowest direction to answer a specific question from.',
                    ['stance' => 12, 'brief' => 12, 'scorecard' => 12, 'goals' => 12, 'provenance' => 12],
                ),
            ],
        );
    }
}
