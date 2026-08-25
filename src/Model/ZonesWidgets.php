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
 * THE catalogue of the per-area ZONES surface.
 *
 * Five design directions were drawn for the zone manager (ngoro-zones-a … -e) and, as with
 * departments, the answer was not "pick one": each reads the same twelve polygons through a
 * different question — the map as geography, the register as rows, the split as one zone at a
 * time, the gallery as shapes, the settings table as an admin afterthought. So all five ship as
 * widgets, the library's headed sections ARE those five directions, and each direction also
 * ships as a {@see WidgetPreset} that adopts its layout whole.
 *
 * DEFAULT: "Split manager" — the direction the design names `defaultPreset: 'split'`. The five
 * widgets declared `on` below are exactly that preset's layout, so a person who has never opened
 * the library and the card wearing "Active" agree.
 *
 * UNLIKE departments this surface is AREA-SCOPED: the same person may lay Ngorongoro's zones out
 * one way and Pololeti's another, so every widget-framework call passes the area's UUID and the
 * stored {@see \Uhifadhi\Entity\WidgetPreference} rows are keyed by (surface, user, area).
 *
 * Static rather than a service: a catalogue is a statement of what a surface ships, it has no
 * dependencies and nothing may vary it at runtime.
 */
final class ZonesWidgets
{
    /** What a stored preference row is keyed by — scoped per area, never org-wide. */
    public const string SURFACE = 'zones';

    /** The direction the design opens on, and the layout the `on` flags below reproduce. */
    public const string DEFAULT_PRESET = 'split';

    public static function catalog(): WidgetCatalog
    {
        $groups = [];
        $presets = [];
        foreach (self::directions() as $letter => [$label, $tradeOff, $presetId, $layout]) {
            $groups[] = new WidgetGroup($letter, $label, $tradeOff);
            $presets[] = new WidgetPreset($presetId, $label, $tradeOff, $layout);
        }

        return new WidgetCatalog(
            self::SURFACE,
            $groups,
            // Declaration order is the dashboard's default order: the copy contract first (what a
            // zone IS, before any number claims otherwise), then the list and the map side by
            // side, then the selected zone, then the one way to make more.
            // Spans are declared WIDEST FIRST (Widget enforces it); the design lists each widget's
            // own default first, which is the same set read in the other direction.
            [
                new Widget('lens', 'A lens, not a fence', 'e', 12, [12, 6]),
                new Widget('picker', 'Zone picker', 'c', 6, [12, 6]),
                new Widget('map', 'Zones on the map', 'a', 6, [12, 9, 6]),
                new Widget('detail', 'Selected zone', 'c', 12, [12, 6, 3]),
                new Widget('import', 'Import zones', 'e', 12, [12]),
                new Widget('kpis', 'Zone KPIs', 'a', 12, [12, 6], on: false),
                new Widget('rail', 'Zone rail', 'a', 3, [12, 6, 3], on: false),
                new Widget('registry', 'Zone register', 'b', 12, [12], on: false),
                new Widget('gallery', 'Zone cards', 'd', 12, [12], on: false),
                new Widget('table', 'The zone table', 'e', 6, [12, 6], on: false),
            ],
            $presets,
            // THE ACTIVE PRESET IS THE LAYOUT: a person who has never chosen sees this design, not
            // the first one declared. It is the design's own `defaultPreset: 'split'`, and the `on`
            // flags above reproduce its layout so the two can never say different things.
            self::DEFAULT_PRESET,
        );
    }

    /**
     * The five directions: the letter the library files them under, what each is called, what the
     * compare index says it costs, the id its preset is applied by, and the layout that IS that
     * design — listed is on, in that order; absent is off.
     *
     * The group id is the design's letter and the preset id is the design's name, exactly as
     * zones.widgets.js declares them; the trade-off line is written ONCE, so a headed section and
     * the preset that adopts it can never say different things about the same design.
     *
     * @return array<string, array{string, string, string, array<string, int>}>
     */
    private static function directions(): array
    {
        return [
            'a' => [
                'Map hero',
                'Best for “where is Olbalbal?”. Weakest for comparing numbers — the rail can only carry two facts per row.',
                'map-hero',
                ['kpis' => 12, 'map' => 9, 'rail' => 3, 'import' => 12],
            ],
            'b' => [
                'Registry first',
                'Best for scanning and editing twelve rows fast. The map falls below the fold, so spatial checks need a scroll.',
                'registry',
                ['kpis' => 12, 'registry' => 12, 'map' => 12, 'import' => 12],
            ],
            'c' => [
                'Split manager',
                'Best for admin work on one zone at a time — rename, inspect, delete. Halves the map and the list.',
                'split',
                ['lens' => 12, 'picker' => 6, 'map' => 6, 'detail' => 12, 'import' => 12],
            ],
            'd' => [
                'Card gallery',
                'Most legible per-zone; twelve mini-maps make shape memorable. Costs a lot of vertical space and no side-by-side compare.',
                'gallery',
                ['kpis' => 12, 'gallery' => 12, 'map' => 12, 'import' => 12],
            ],
            'e' => [
                'Inside Settings',
                'Zero new navigation and the most honest home for an admin feature. Ceiling is low — the section can never grow past two cards.',
                'settings',
                ['lens' => 12, 'table' => 6, 'map' => 6, 'import' => 12],
            ],
        ];
    }
}
