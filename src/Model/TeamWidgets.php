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
 * THE catalogue of the org-wide team surface.
 *
 * Team is TWO questions, so the library has two headed sections. "People" is the roster and the
 * single control that changes what someone may do — their position. "Positions" is the catalogue
 * of those permission bundles, and it is where the whole design argument lives: a position
 * belongs to one department and its name is unique only inside it, so the screen has to make two
 * same-named Analysts impossible to read as one.
 *
 * Positions was therefore drawn FIVE ways (uhifadhi-ops/designs/ngoro-positions-{a..e}.html) and
 * all five ship — as ALTERNATIVES, not additions. They differ only in how the same list is laid
 * out, so exactly one is on at a time and each preset turns on exactly one of them. That is also
 * why every preset carries `people` at full width: the five designs were drawn as whole Team
 * screens, and People is the half that never changed.
 *
 * A preset's description is that direction's own trade-off line from the compare index, verbatim
 * — what the design said a direction costs is what the library says about it.
 *
 * Static rather than a service: a catalogue is a statement of what a surface ships, it has no
 * dependencies and nothing may vary it at runtime.
 *
 * @see \Uhifadhi\Service\TeamService::context() the one context every templates/team/_w_*.twig receives
 */
final class TeamWidgets
{
    /** What a stored preference row is keyed by. Org-wide, so the area is always null. */
    public const string SURFACE = 'team';

    /**
     * The direction a person who has never chosen opens on: the grouped table, because it is the
     * smallest change from the page Team already was and its create row picks the department by
     * WHERE you type — so the department can never be forgotten even on day one.
     */
    public const string DEFAULT_PRESET = 'positions_a';

    public static function catalog(): WidgetCatalog
    {
        $presets = [];
        foreach (self::directions() as $id => [$label, $tradeOff]) {
            $presets[] = new WidgetPreset($id, $label, $tradeOff, ['people' => 12, $id => 12]);
        }

        return new WidgetCatalog(
            self::SURFACE,
            [
                new WidgetGroup(
                    'people',
                    'People',
                    'The roster and the single control that changes what someone may do — their position.',
                ),
                new WidgetGroup(
                    'positions',
                    'Positions',
                    'A position belongs to one department and its name is unique only inside it. '
                    .'Five ways to lay that out — pick the one your org reads best; they are alternatives, '
                    .'so keep one on.',
                ),
            ],
            // Declaration order is the dashboard's default order: who is on the team, then the
            // positions that decide what they may do. Every positions widget spans the full row
            // only — each is a whole screen's worth of table, and half of one reads as neither.
            [
                new Widget('people', 'People', 'people', 12, [12], on: true),
                new Widget('positions_a', 'Positions — grouped table', 'positions', 12, [12], on: true),
                new Widget('positions_b', 'Positions — department filter chips', 'positions', 12, [12], on: false),
                new Widget('positions_c', 'Positions — department cards', 'positions', 12, [12], on: false),
                new Widget('positions_d', 'Positions — qualified names', 'positions', 12, [12], on: false),
                new Widget('positions_e', 'Positions — split manager', 'positions', 12, [12], on: false),
            ],
            $presets,
            self::DEFAULT_PRESET,
        );
    }

    /**
     * The five directions Positions was drawn in: what each is called and what the compare index
     * says it costs. The preset id IS the widget id it turns on — there is one widget per
     * direction and one direction per preset, so a third name would only be a thing to get wrong.
     *
     * @return array<string, array{string, string}>
     */
    private static function directions(): array
    {
        return [
            'positions_a' => [
                'Grouped table',
                'Smallest change to the current page and the create flow picks the department by where you type; a long org makes one very long table.',
            ],
            'positions_b' => [
                'Department filter chips',
                'Short page at any org size and the scope is always stated; two clicks to compare two departments.',
            ],
            'positions_c' => [
                'Department cards',
                'The card boundary makes the two Analysts visually impossible to merge; costs the most vertical space.',
            ],
            'positions_d' => [
                'Qualified names',
                'One sortable list, and the qualified name survives copy/paste, search and audit lines; the department column repeats on every row.',
            ],
            'positions_e' => [
                'Split manager',
                'Scales furthest and you are always demonstrably inside one department; no way to see all positions at once.',
            ],
        ];
    }
}
