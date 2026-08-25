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
 * Each direction was drawn as a WHOLE screen, so each also ships as a {@see WidgetPreset}: one
 * click adopts option A's layout entire, rather than asking a first-time visitor to assemble it
 * widget by widget. The direction's letter is its group id AND its preset id, and its trade-off
 * line is written ONCE — {@see self::directions()} — so a section and the preset that adopts it
 * can never say different things about the same design.
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
        $groups = [];
        $presets = [];
        foreach (self::directions() as $letter => [$label, $tradeOff, $layout]) {
            $groups[] = new WidgetGroup($letter, $label, $tradeOff);
            $presets[] = new WidgetPreset($letter, $label, $tradeOff, $layout);
        }

        return new WidgetCatalog(
            self::SURFACE,
            $groups,
            // Declaration order is the dashboard's default order: the numbers first, then the
            // objects they count, then the people in them. Each `note` is the ONE line the
            // add-widget picker prints under the widget's own rendered preview.
            [
                new Widget('kpis', 'Department KPIs', 'a', 12, [12, 6], note: 'Four counts: departments, people placed, positions, modules attached.'),
                new Widget('cards', 'Department cards', 'a', 12, [12], note: 'One plate per department — its modules, its positions and who holds them.'),
                new Widget('registry', 'Team registry', 'b', 12, [12, 6], note: 'Every person as a row: name, tier, position, department.'),
                new Widget('matrix', 'Departments × modules', 'c', 12, [12], on: false, note: 'Departments down, modules across — every attachment as one dot.'),
                new Widget('lanes', 'Org chart lanes', 'd', 12, [12], on: false, note: 'The org chart as lanes, with each department’s staffing at a glance.'),
                new Widget('lens', 'Lens preview', 'e', 12, [12], on: false, note: 'The same area, twice, as two departments meet it.'),
                new Widget('shared', 'Shared modules', 'a', 12, [12, 6], on: false, note: 'The modules two or more departments claim, and who claims them.'),
            ],
            $presets,
            // The composition this surface SHIPS WITH is not one of the five directions — it takes
            // the numbers and the plates from A and the registry from B. In the active-preset model
            // there is no layout that is not a preset, so it leads the strip as a built-in in its
            // own right, named here rather than left as a generic "Default layout".
            defaultLabel: 'The departments board',
            defaultDescription: 'What the surface ships with: the counts, the department plates, then the people in them.',
        );
    }

    /**
     * The five directions: what each is called, what the compare index says it costs, and the
     * layout that IS that design — listed is on, in that order; absent is off.
     *
     * @return array<string, array{string, string, array<string, int>}>
     */
    private static function directions(): array
    {
        return [
            'a' => [
                'Department cards',
                'Most room to breathe and the easiest to grow; costs a new top-level nav entry for an object edited three times a year.',
                ['kpis' => 12, 'cards' => 12, 'shared' => 12],
            ],
            'b' => [
                'Team view',
                'Puts person → position → department on one screen and adds no nav; the page gets long.',
                ['registry' => 12, 'kpis' => 12],
            ],
            'c' => [
                'Configuration matrix',
                'Fastest possible configuration and the shared columns are self-evident; says nothing about people.',
                ['matrix' => 12, 'kpis' => 12],
            ],
            'd' => [
                'Org chart',
                'Instantly legible to a director and great for staffing gaps; weakest surface for editing attachments.',
                ['lanes' => 12, 'kpis' => 12],
            ],
            'e' => [
                'Lens preview',
                'The only direction that shows the actual payoff of the feature; management is one level deeper.',
                ['lens' => 12, 'cards' => 12],
            ],
        ];
    }
}
