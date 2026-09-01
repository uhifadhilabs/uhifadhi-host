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
 * THE FIVE DIRECTIONS THE AREA OVERVIEW WAS DRAWN IN, as presets.
 *
 * The design app explored this page as five lettered frames with a compare
 * index, and the answer was not "pick one": each is a genuine conviction about
 * what an area manager opens the page for, and which one is right depends on the
 * area, the week and the person. So all five ship, adoptable in one click, and
 * each direction's trade-off line from the compare index is its description
 * VERBATIM — what the design said about a direction is what the product says
 * about it.
 *
 * The composition the HOST ships is none of the five: it is the
 * direction-neutral answer — who you are, what is happening now, what needs you,
 * where, then one card from each installed module. A fresh person opens on it,
 * and {@see WidgetCatalog::builtins()} leads the strip with it.
 *
 * A LAYOUT MAY NAME A MODULE'S WIDGET. Four of the five do, because four of them
 * are convictions about operational work and every operational widget on this
 * surface belongs to a module. {@see \Uhifadhi\Service\AreaOverviewCatalogue}
 * therefore filters each layout to the widgets THIS AREA actually has before
 * handing it to the catalogue, and drops a preset that has nothing left —
 * "everything off" is not a design. That is the same tolerance
 * {@see \Uhifadhi\Service\WidgetService::resolve()} gives a stored preference,
 * moved one step earlier because here the catalogue itself varies per area.
 *
 * Static rather than a service: these are statements of what the surface ships.
 */
final class AreaOverviewPresets
{
    /** What the catalogue's own composition is called when it leads the strip. */
    public const string SHIPPED_LABEL = 'The area overview';

    public const string SHIPPED_DESCRIPTION = 'What the host ships: the identity band, the right-now strip, what needs attention, the operational map, and one card from each installed module. Direction-neutral on purpose — adopt one of the five below to lead with something sharper.';

    /**
     * Letter => [label, the compare index's trade-off line, the layout].
     *
     * @return array<string, array{string, string, array<string, int>}>
     */
    public static function directions(): array
    {
        return [
            'a' => [
                'Pulse first',
                'The morning read as one column, newest at the top: the live strip, then what needs you, then the stream of everything that happened while you were away, and only then the map. Fastest way to find out what changed overnight; the weakest at answering “where”, and a quiet week reads as an empty page.',
                ['ident' => 12, 'nowbar' => 12, 'attention' => 12, 'pulse' => 12, 'map' => 12, 'presence' => 6, 'in_flow' => 6],
            ],
            'b' => [
                'Map as ground',
                'The area IS the map: it takes the height of the screen and everything else docks to it, so “where” is answered before “what”. Unbeatable for spotting a cluster, a stranded patrol or an unwatched corner; worst for money, paperwork and anything that has no coordinates.',
                ['ident' => 12, 'mapdock' => 12, 'nowbar' => 12, 'attention' => 6, 'presence' => 6],
            ],
            'c' => [
                'Module columns',
                'After a thin host band the page is literally the sum of its modules — one column each, under its own heading, contributed whole. The only direction where the architecture is visible to the person using it and a new module needs no layout decision; it repeats a heading three times and wastes the top-left corner on a column that may be quiet today.',
                ['ident' => 12, 'nowbar' => 12, 'pl_column' => 6, 'in_column' => 6, 'nextmod' => 12],
            ],
            'd' => [
                'Duty board',
                'A control-room wall: every number the area has, at once, dense, legible across a room, every tile a link into the list behind it. Nothing needs scrolling and nothing is prose. Superb for a shift start and for a screen nobody sits at; it tells you a count is wrong and never what happened.',
                ['ident' => 12, 'board' => 12, 'attention' => 6, 'pl_now' => 6, 'in_flow' => 6, 'presence' => 6, 'map' => 12],
            ],
            'e' => [
                'Attention queue',
                'A worklist, not a report. The page opens on the things that go wrong if nobody touches them today — with an owner and an age on every one — then the two queues that feed it, then the map, and the identity facts sink to a footer band. The most honest about the morning; it hides a good day, and a manager who wants a number has to go and find it.',
                ['attention' => 12, 'pl_obsq' => 12, 'in_flow' => 6, 'pl_gaps' => 6, 'map' => 12, 'ident' => 12],
            ],
        ];
    }
}
