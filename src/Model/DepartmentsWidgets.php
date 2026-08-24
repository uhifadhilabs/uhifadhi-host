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
 * THE catalogue of the org-wide departments surface.
 *
 * Five design directions were drawn for departments (ngoro-departments-a … -e) and the answer
 * was not "pick one": each reads the same org through a different question — the cards read it
 * as objects, the registry as people, the matrix as configuration, the lanes as an org chart,
 * the lens as the payoff. So all five ship, as widgets, and the library's headed sections ARE
 * the five directions; their one-line descriptions are the compare index's own trade-off lines,
 * so what the design said about a direction is what the library says about its section.
 *
 * Static rather than a service: a catalogue is a statement of what a surface ships, it has no
 * dependencies and nothing may vary it at runtime.
 */
final class DepartmentsWidgets
{
    /** What a stored preference row is keyed by. Org-wide, so the area is always null. */
    public const string SURFACE = 'departments';

    public static function catalog(): WidgetCatalog
    {
        return new WidgetCatalog(
            self::SURFACE,
            [
                new WidgetGroup('a', 'Department cards', 'Most room to breathe and the easiest to grow; costs a new top-level nav entry for an object edited three times a year.'),
                new WidgetGroup('b', 'Team view', 'Puts person → position → department on one screen and adds no nav; the page gets long.'),
                new WidgetGroup('c', 'Configuration matrix', 'Fastest possible configuration and the shared columns are self-evident; says nothing about people.'),
                new WidgetGroup('d', 'Org chart', 'Instantly legible to a director and great for staffing gaps; weakest surface for editing attachments.'),
                new WidgetGroup('e', 'Lens preview', 'The only direction that shows the actual payoff of the feature; management is one level deeper.'),
            ],
            // Declaration order is the dashboard's default order: the numbers first, then the
            // objects they count, then the people in them.
            [
                new Widget('kpis', 'Department KPIs', 'a', 12, [12, 6]),
                new Widget('cards', 'Department cards', 'a', 12, [12]),
                new Widget('registry', 'Team registry', 'b', 12, [12, 6]),
                new Widget('matrix', 'Departments × modules', 'c', 12, [12], on: false),
                new Widget('lanes', 'Org chart lanes', 'd', 12, [12], on: false),
                new Widget('lens', 'Lens preview', 'e', 12, [12], on: false),
                new Widget('shared', 'Shared modules', 'a', 12, [12, 6], on: false),
            ],
        );
    }
}
