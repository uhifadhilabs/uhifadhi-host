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

/**
 * THE catalogue of the org-wide performance board — `GET /departments/performance`.
 *
 * Ported from the settled design (departments/performance.html), whose four panels each carry a
 * "rendered with: widget" note: the board itself, the rank-shift list, the module-coverage table
 * and the drill-in. They ship as those four widgets, so the board arranges like every other
 * dashboard in the app rather than being one screen someone drew.
 *
 * ORG-WIDE, like the departments dashboard: every widget-framework call passes a null area, so
 * there is one stored layout per person and not one per area.
 *
 * The board's COLUMNS are not declared here and could not be: they are whatever KPIs the
 * installed modules reported ({@see \Uhifadhi\Service\DepartmentKpiService::columns()}). A
 * catalogue says which widgets exist; the modules say what is in them.
 */
final class PerformanceBoardWidgets
{
    /** What a stored preference row is keyed by. Org-wide, so the area is always null. */
    public const string SURFACE = 'departments-performance';

    public static function catalog(): WidgetCatalog
    {
        return new WidgetCatalog(
            self::SURFACE,
            [
                new WidgetGroup('board', 'The board', 'Every department as a row, against the same KPIs over the same period.'),
                new WidgetGroup('reading', 'Reading it', 'What moved, who leads with what, and one department in full.'),
            ],
            [
                new Widget('board', 'Department board', 'board', 12, [12], note: 'Rows are departments, columns are the KPIs of the modules they share.'),
                new Widget('shifts', 'Rank shifts', 'reading', 6, [12, 9, 6], note: 'What moved against the period before, and what has no row to fill.'),
                new Widget('coverage', 'Module coverage', 'reading', 6, [12, 9, 6], note: 'Which department leads with which module, and which of those compute a KPI today.'),
                new Widget('drill', 'Drill-in', 'reading', 12, [12, 9], note: 'One department in full: its figures, its goals and what its modules did not report.'),
            ],
            [
                new WidgetPreset(
                    'board',
                    'The board',
                    'The comparison first and the commentary under it — the reading the design settled on; a long board pushes the drill-in a long way down.',
                    ['board' => 12, 'shifts' => 6, 'coverage' => 6, 'drill' => 12],
                ),
                new WidgetPreset(
                    'compare',
                    'Comparison only',
                    'Nothing but the table, for a screen that is being projected; loses every sentence that stops a board being read as a league table of worth.',
                    ['board' => 12],
                ),
                new WidgetPreset(
                    'onedept',
                    'One department',
                    'Puts the drill-in above the board, for someone who came to read one department and compare second; the comparison needs a scroll.',
                    ['drill' => 12, 'board' => 12, 'shifts' => 6, 'coverage' => 6],
                ),
            ],
        );
    }
}
