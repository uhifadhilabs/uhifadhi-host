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
 * THE catalogue of ONE department's Overview tab — the surface behind `GET /departments/{uuid}`.
 *
 * Ported from the settled design (departments/ecology/overview.widgets.js): its groups are these
 * groups, its widgets are these widgets at these widths, and its five directions are these five
 * presets, each carrying the design's own trade-off line.
 *
 * ONE LAYOUT, EVERY DEPARTMENT. The stored preference row is keyed by
 * ({@see self::SURFACE}, user, area) and this surface passes a NULL AREA — so a person arranges
 * the department record once and every department they open afterwards wears that arrangement.
 * That is deliberate: the widgets are the shape of the RECORD, not of any one department, and a
 * per-department layout would mean re-arranging Ecology to learn what Tourism looks like. The
 * uuid in the URL changes the DATA in these widgets and never their arrangement.
 *
 * Static rather than a service: a catalogue states what a surface ships, and nothing may vary it
 * at runtime.
 */
final class DepartmentDetailWidgets
{
    /**
     * What a stored preference row is keyed by. Note it is NOT keyed by the department — see the
     * class docblock: one layout per person, applied to every department.
     */
    public const string SURFACE = 'department-detail';

    public static function catalog(): WidgetCatalog
    {
        return new WidgetCatalog(
            self::SURFACE,
            [
                new WidgetGroup('record', 'The record', 'Who this department is, what it attaches, and where those modules actually run.'),
                new WidgetGroup('people', 'Positions and people', 'The positions this department owns and the people holding them. Membership follows the position.'),
                new WidgetGroup('lens', 'The lens', 'What the department does to the product: which modules lead, for whom, and where.'),
                new WidgetGroup('danger', 'Deleting', 'The one destructive control, with the consequence list that makes it readable.'),
            ],
            // Declaration order IS the default layout — the design's two-pane workbench, read as
            // twelve columns: the narrow config rail on the left, the roster workbench beside it.
            [
                new Widget('principle', 'A lens, not a fence', 'lens', 12, [12, 9, 6], note: 'Why a department changes emphasis and never access.'),
                new Widget('identity', 'Identity', 'record', 3, [12, 6, 3], note: 'Name, code and uuid — the address this record lives at.'),
                new Widget('positions', 'Positions & permissions', 'people', 9, [12, 9, 6], note: 'Every position, its permissions and who holds it.'),
                new Widget('modules', 'Modules attached', 'record', 3, [12, 6, 3], note: 'The attached modules, which are shared, and the way to attach another.'),
                new Widget('members', 'Members', 'people', 9, [12, 9, 6], note: 'Everyone in the department and the position that placed them.'),
                new Widget('footprint', 'Where its modules run', 'record', 3, [12, 6, 3], note: 'The areas this department’s modules are switched on in — derived, never tracked.'),
                new Widget('attachments', 'Attachments, in full', 'record', 9, [12, 9, 6], note: 'Each attached module in full: who else claims it, where it runs, how to detach it.'),
                new Widget('rename', 'Rename', 'record', 3, [12, 6, 3], note: 'Rename the department; the uuid and every link to it are untouched.'),
                new Widget('lens', 'The department lens, previewed', 'lens', 12, [12, 9], note: 'A live preview of the Modules tab as one of this department’s people sees it.'),
                new Widget('delete', 'Delete this department', 'danger', 9, [12, 9, 6], note: 'The one destructive control, with the consequence list spelled out above the button.'),
                new Widget('kpis', 'The four counts', 'record', 12, [12, 9, 6], on: false, note: 'Modules, positions, people placed and areas, as one four-plate strip.'),
            ],
            [
                new WidgetPreset(
                    'workbench',
                    'Two-pane workbench',
                    'Best for actually working: config stays visible while you edit the roster; costs the widest layout and collapses to a plain stack under 1040px.',
                    ['principle' => 12, 'identity' => 3, 'positions' => 9, 'modules' => 3, 'members' => 9, 'footprint' => 3, 'attachments' => 9, 'rename' => 3, 'delete' => 9, 'lens' => 12],
                ),
                new WidgetPreset(
                    'profile',
                    'Profile record',
                    'Reads top-to-bottom like a record and every control sits beside what it changes; on a big department the page gets long and there is no way to jump.',
                    ['identity' => 12, 'modules' => 12, 'positions' => 12, 'members' => 12, 'footprint' => 12, 'attachments' => 12, 'rename' => 12, 'delete' => 12],
                ),
                new WidgetPreset(
                    'minidash',
                    'Mini-dashboard',
                    'Continuous with the dashboard and scannable in a second; the plates count small numbers that look thin at that type size.',
                    ['kpis' => 12, 'principle' => 12, 'modules' => 6, 'positions' => 6, 'members' => 6, 'footprint' => 6, 'attachments' => 6, 'rename' => 6, 'delete' => 12],
                ),
                new WidgetPreset(
                    'lensfirst',
                    'Lens first',
                    'The only direction that shows what the department does and kills the “is this a permission?” question on sight; pushes management below the fold.',
                    ['lens' => 12, 'principle' => 12, 'positions' => 6, 'members' => 6, 'attachments' => 6, 'rename' => 6, 'delete' => 12],
                ),
                new WidgetPreset(
                    'short',
                    'Short overview',
                    'Familiar, keeps every screen short, and gives Settings somewhere honest to live; hides three quarters of the department behind a click.',
                    ['kpis' => 12, 'modules' => 6, 'members' => 6, 'footprint' => 12],
                ),
            ],
        );
    }
}
