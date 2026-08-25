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
 * The zone layer's colours and plate codes — stated ONCE, here, and read by every place a zone is
 * drawn: the map's GeoJSON, its legend, the picker swatches, the register, the cards, the table.
 *
 * A layer's colour is DATA, not theme: the same swatch must mean the same zone in the legend, in
 * the list beside it and on the polygon itself, in light and in dark. So the palette is fixed
 * hexes (never a house token), it is served to the map inside the GeoJSON rather than duplicated
 * in JavaScript, and the twelve values below are the design's own — ngoro-zones-a … -e.
 *
 * The colour follows the zone's CREATION ORDER, not its name: renaming a zone must not repaint it,
 * and an import that lands twelve zones at once paints them in file order. The same index is the
 * zone's ZN·NN plate, which is why both come from one call.
 */
final readonly class ZonePalette
{
    /**
     * Twelve hues that stay apart on satellite imagery. Past the twelfth zone it wraps — a
     * thirteenth zone reusing the first colour is far better than a thirteenth zone with no
     * colour at all, and the label on the polygon still names it.
     *
     * @var list<string>
     */
    public const array COLORS = [
        '#8DA0B3', '#E8C15A', '#E0854A', '#4FA8E8',
        '#5FBF7A', '#C85A93', '#9DBF4A', '#9B6BD8',
        '#3FC7D4', '#6E7FE0', '#D9584B', '#C9A67E',
    ];

    /** The AOI boundary's own legend colour — the same green `uhifadhi/boundary` draws it in. */
    public const string AOI = '#49E6B4';

    /** @param int $index the zone's zero-based position in creation order */
    public static function color(int $index): string
    {
        return self::COLORS[$index % \count(self::COLORS)];
    }

    /** The zone's plate: ZN·01, ZN·02, … — the zones equivalent of a module's PL·NN. */
    public static function plate(int $index): string
    {
        return \sprintf('ZN·%02d', $index + 1);
    }
}
