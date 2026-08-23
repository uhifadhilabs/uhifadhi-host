/*
 * How an area-of-interest boundary is drawn — ONCE, for the whole platform.
 *
 * The rule is that the same layer renders identically everywhere, and the
 * boundary is the layer that matters most: it must be unmistakable where the
 * area is, on satellite imagery and on street tiles, in light theme and dark.
 *
 * This is the app's settled boundary design, kept from the original app (see
 * `git show refs/archive/main:assets/controllers/map_controller.js`), and it
 * works in two parts:
 *
 *  1. CASING — a wide white under-stroke beneath a jade over-stroke. Either
 *     stroke alone disappears over some imagery (white over cloud, jade over
 *     vegetation); the pair never does. No fill: a boundary states where the
 *     area is, it must not tint the imagery inside it.
 *
 *  2. SCRIM — everything OUTSIDE the boundary is dimmed, by drawing a
 *     world-covering polygon with the area's rings punched out as holes. That
 *     turns the area into figure and the rest of the world into ground, which
 *     is what actually makes the boundary read at a glance. It is toggleable,
 *     because sometimes you need to see what is just outside.
 *
 * Imported by the host's own map controller AND by module bundles
 * (`uhifadhi/boundary`), so there is exactly one definition of these numbers in
 * the platform and nothing to keep in sync.
 */

/** The white halo that lifts the line off whatever is beneath it. */
export const BOUNDARY_CASING = { color: '#ffffff', weight: 6, opacity: 0.9, fill: false, interactive: false };

/** The line itself: the app's jade. */
export const BOUNDARY_LINE = { color: '#49E6B4', weight: 3, fill: false, interactive: false };

/** The outside-the-area scrim. */
export const BOUNDARY_SCRIM = { stroke: false, fillColor: '#060a08', fillOpacity: 0.42, interactive: false };

/*
 * A boundary reaches these controllers in two shapes: the host hands over a
 * GeoJSON FeatureCollection, a module hands over the bare geometry its PostGIS
 * column stores. Both are accepted, so neither has to reshape its data to be
 * drawn the same way.
 */
function polygonRings(geojson) {
    const rings = [];
    const collect = (polygon) => rings.push(polygon[0].map(([lng, lat]) => [lat, lng]));
    const fromGeometry = (geometry) => {
        if (geometry?.type === 'Polygon') {
            collect(geometry.coordinates);
        } else if (geometry?.type === 'MultiPolygon') {
            geometry.coordinates.forEach(collect);
        }
    };

    if (geojson?.type === 'FeatureCollection') {
        (geojson.features ?? []).forEach((feature) => fromGeometry(feature?.geometry));
    } else if (geojson?.type === 'Feature') {
        fromGeometry(geojson.geometry);
    } else {
        fromGeometry(geojson);
    }

    return rings;
}

/**
 * A world-covering polygon with the boundary rings as holes → dims the outside.
 * Null when the geometry has no polygon rings to punch out (a scrim with no
 * hole would simply black the map out).
 */
export function buildScrim(L, geojson) {
    const holes = polygonRings(geojson);
    if (holes.length === 0) {
        return null;
    }
    const world = [[-89, -179], [-89, 179], [89, 179], [89, -179]];

    return L.polygon([world, ...holes], BOUNDARY_SCRIM);
}

/**
 * Draw a boundary on `map`: the scrim (when asked for), then the casing, then
 * the line — in that order, so the strokes sit above the dimming.
 *
 * THE CONTRACT, deliberately singular: this returns ONE Leaflet layer group —
 * the casing and the line — or null when there is nothing to draw. Every caller
 * fits the map with `boundary.getBounds()` and needs no null check beyond
 * "did I get a layer".
 *
 * It returned `{ line, scrim }` for one commit and that shape cost a live
 * `boundary.getBounds is not a function` crash which took the whole controller
 * down with it. Hence: one return value, and it is always a layer.
 *
 * The scrim is NOT in that group — it covers the world, so its bounds are the
 * world and fitting to them would zoom every map out to the whole planet. The
 * DIM control's handle hangs off the group as `.scrimLayer` instead (null when
 * no scrim was drawn).
 *
 * `scrim: false` is for the small close-zoom plates, where the whole frame is
 * inside the area and the dimming would be noise rather than orientation. It
 * only sets the STARTING state: the layer is built either way, so those plates
 * still carry a working DIM control to switch it on.
 */
export function drawBoundary(L, map, geojson, { scrim = true } = {}) {
    if (!geojson) {
        return null;
    }

    // Always BUILT, so the DIM control exists on every map; `scrim` only says
    // whether it starts switched on.
    const scrimLayer = buildScrim(L, geojson);
    if (scrim) {
        scrimLayer?.addTo(map);
    }

    const casing = L.geoJSON(geojson, { style: BOUNDARY_CASING });
    const line = L.geoJSON(geojson, { style: BOUNDARY_LINE });
    const boundary = L.featureGroup([casing, line]).addTo(map);

    // The one handle a DIM control needs; never part of the group's bounds.
    boundary.scrimLayer = scrimLayer;

    return boundary;
}
