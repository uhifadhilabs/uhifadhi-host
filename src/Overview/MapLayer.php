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

namespace Uhifadhi\Overview;

/**
 * ONE LAYER OF THE OPERATIONAL PLATE, WITH ITS LEGEND.
 *
 * THE MAP-LEGEND CONTRACT: every layer ships a legend, and the same layer
 * renders identically everywhere it is drawn. The host holds one plate; each
 * layer on it is contributed by the module that owns the data, carries its own
 * swatch, and the legend is grouped by contributor — which is the only way a
 * person can tell why a layer vanished.
 *
 * WHAT IS ON BY DEFAULT IS OPERATIONAL — boundary, stations, today's tracks,
 * open incidents. WHAT IS OFF BY DEFAULT IS SCIENTIFIC — tree-cover loss, fire
 * detections. The science is not deleted: it is one click away in the layer
 * list, with its legend, exactly where it was. It is simply no longer the first
 * thing an area manager is shown at 07:00.
 *
 * THE GEOMETRY TRAVELS AS GEOJSON, in a FeatureCollection the layer builds for
 * one area. A layer with nothing to draw returns an empty collection and still
 * ships its legend entry — "no open incidents today" is an answer, and a legend
 * that appears and disappears with the data is a legend nobody can rely on.
 */
final readonly class MapLayer
{
    /** A line: the swatch is a rule, not a block. */
    public const string STYLE_LINE = 'line';
    /** A filled shape or a point: the swatch is a block. */
    public const string STYLE_FILL = 'fill';
    /**
     * THE AREA'S OWN OUTLINE, drawn the platform's one way: a white casing under
     * a jade line, with everything outside it dimmed by a scrim the map's DIM
     * control switches. Those numbers live once, in assets/map_boundary.js, and
     * every map in the product reads them from there — so a layer that IS the
     * boundary says so rather than asking for a 2px line and quietly reading
     * differently from every other plate. Only the host declares one: a module
     * does not own where the area ends.
     */
    public const string STYLE_BOUNDARY = 'boundary';

    /**
     * @param string               $id       unique across the plate; `<module>.<layer>` by convention
     * @param string               $swatch   the legend's colour, as CSS — A LAYER'S COLOUR IS DATA, so it
     *                                       is the same in light and dark and the module states it once
     * @param int|null             $count    what the legend prints after the label ("Open · 31"); null prints nothing
     * @param bool                 $on       whether the layer is drawn before anybody touches the legend
     * @param bool                 $live     whether the surface's one polling endpoint refreshes it
     * @param array<string, mixed> $features a GeoJSON FeatureCollection
     */
    public function __construct(
        public string $id,
        public string $moduleSlug,
        public string $groupLabel,
        public string $label,
        public string $swatch,
        public array $features,
        public string $style = self::STYLE_FILL,
        public ?int $count = null,
        public bool $on = true,
        public bool $live = false,
    ) {
        if ('' === $id || '' === $label || '' === $groupLabel) {
            throw new \InvalidArgumentException('A map layer needs an id, a label and the group its legend sits under.');
        }
        if (!\in_array($style, [self::STYLE_LINE, self::STYLE_FILL, self::STYLE_BOUNDARY], true)) {
            throw new \InvalidArgumentException(\sprintf('Map layer "%s" asks for the swatch style "%s", which the legend does not draw.', $id, $style));
        }
        if ('FeatureCollection' !== ($features['type'] ?? null)) {
            throw new \InvalidArgumentException(\sprintf('Map layer "%s" must carry a GeoJSON FeatureCollection, empty if it has nothing to draw.', $id));
        }
        // AND ITS `features` LIST, WHICH GEOJSON REQUIRES. The plate, the dock
        // and the legend all walk it; a collection without one would not fail
        // here, where the module built it, but inside the host's template, on
        // the page an area manager opens at 07:00.
        if (!\is_array($features['features'] ?? null)) {
            throw new \InvalidArgumentException(\sprintf('Map layer "%s" must carry a GeoJSON FeatureCollection, empty if it has nothing to draw — its "features" list is missing.', $id));
        }
    }
}
