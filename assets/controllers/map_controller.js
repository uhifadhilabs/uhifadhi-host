import { Controller } from '@hotwired/stimulus';
import { satelliteLayer, streetLayer } from 'uhifadhi/basemaps';
import { drawBoundary } from 'uhifadhi/boundary';
import { mountMapChrome } from 'uhifadhi/map-chrome';

/*
 * Deforestation map: the NCA boundary (embedded `boundary` value) plus per-year
 * forest-loss footprints fetched from `forestLossUrl`, over a switchable OSM /
 * satellite base. Loss polygons are graduated by year and filtered by a year
 * range; clicking one shows its year + hectares.
 *
 * Rendered with Leaflet (self-hosted UMD → window.L, loaded via a <script> tag in
 * the template). Chosen over MapLibre deliberately: this map is raster tiles +
 * GeoJSON overlays, which Leaflet does in plain DOM/SVG — no WebGL2 requirement
 * and no web worker, the two things that made MapLibre fail silently (blank map)
 * in constrained environments and under AssetMapper. Revisit MapLibre only if we
 * ever serve vector tiles.
 */

// Hansen GFC year ramp (plasma), 2001 → 2023 — MUST match LossYearPaletteService::STOPS.
const RAMP = [
    [2001, [0x0d, 0x08, 0x87]],
    [2008, [0x7e, 0x03, 0xa8]],
    [2014, [0xcc, 0x44, 0x78]],
    [2019, [0xf8, 0x95, 0x40]],
    [2023, [0xf0, 0xf9, 0x21]],
];

function yearColor(year) {
    if (year <= RAMP[0][0]) return rgb(RAMP[0][1]);
    for (let i = 1; i < RAMP.length; i++) {
        const [y1, c1] = RAMP[i - 1];
        const [y2, c2] = RAMP[i];
        if (year <= y2) {
            const t = (year - y1) / (y2 - y1);
            return rgb(c1.map((v, k) => Math.round(v + t * (c2[k] - v))));
        }
    }
    return rgb(RAMP[RAMP.length - 1][1]);
}

const rgb = (c) => `rgb(${c[0]},${c[1]},${c[2]})`;

export default class extends Controller {
    static values = { boundary: Object, forestLossUrl: String, classLayerUrl: String, classPalette: Object, rasterUrl: String, rasterBounds: Array };
    static targets = ['canvas', 'frame', 'fromYear', 'toYear', 'bar', 'rangeFill', 'rangeSummary'];

    static YEAR_MIN = 2001;
    static YEAR_MAX = 2023;

    connect() {
        const L = window.L;
        if (!L) {
            console.error('[map] window.L (Leaflet) is not loaded — check the <script> tag');
            return;
        }
        this.L = L;

        // Every control comes from the platform chrome module (built in JS, see
        // the map module's chrome.js) — the same instrument a module's maps wear.
        this.map = L.map(this.canvasTarget, { zoomControl: false });
        this.canvasTarget.classList.add('map-chrome-host');

        // One definition of the two bases for the whole platform (the map module's basemaps.js):
        // satellite is Google's official Map Tiles API, falling back to keyless
        // Esri imagery when no UHIFADHI_GOOGLE_MAPS_API_KEY is configured.
        this.bases = {
            osm: streetLayer(L),
            satellite: satelliteLayer(L, this.map),
        };
        this.bases.satellite.addTo(this.map); // satellite is the default base

        this.drawBoundary();

        // The chrome goes on after the boundary, so the DIM pill has a scrim to
        // switch. Fullscreen takes the whole frame — map plus the loss-year
        // strip — so filtering stays available there.
        this.chrome = mountMapChrome(L, this.map, this.canvasTarget.parentElement, {
            bases: this.bases,
            scrim: this.dimLayer,
            scrimOn: true,
            fullscreenTarget: this.hasFrameTarget ? this.frameTarget : this.canvasTarget.parentElement,
            // Re-frame the area when the viewport changes size, or full screen
            // would just show more of Tanzania around the same small boundary.
            onResize: () => this.boundaryLayer && this.map.fitBounds(this.boundaryLayer.getBounds(), { padding: [40, 40] }),
        });

        // Both overlays are optional — a module map may carry a class layer, the area map forest loss.
        if (this.hasForestLossUrlValue && this.forestLossUrlValue) this.loadForestLoss();
        if (this.hasClassLayerUrlValue && this.classLayerUrlValue) this.loadClassLayer();
        // A continuous raster surface (NDVI, biomass, suitability …) as an ImageOverlay, under vectors.
        if (this.hasRasterUrlValue && this.rasterUrlValue && this.hasRasterBoundsValue && this.rasterBoundsValue.length === 2) {
            this.rasterLayer = this.L.imageOverlay(this.rasterUrlValue, this.rasterBoundsValue, { opacity: 0.8 }).addTo(this.map);
            this.rasterLayer.bringToBack();
        }
    }

    // Tear the Leaflet map down when Stimulus disconnects (Turbo/soft-nav navigation, cached previews).
    // Without this, revisiting the page runs L.map() on an already-initialized container — Leaflet
    // throws, the map never builds, and its zoom/controls flash in then vanish.
    disconnect() {
        this.chrome?.destroy();
        this.chrome = null;
        if (this.map) {
            // stop() first: a zoom animation still in flight fires its
            // transitionend on a pane remove() has already detached, and Leaflet
            // throws on '_leaflet_pos'. Navigating mid-zoom is ordinary.
            this.map.stop();
            this.map.remove();
            this.map = null;
        }
    }

    // A dissolved per-class layer (e.g. land cover), coloured by the label→colour palette. Read-only:
    // fetched from the module's .geojson endpoint (PostGIS-dissolved), rendered like forest loss.
    async loadClassLayer() {
        const res = await fetch(this.classLayerUrlValue);
        if (!res.ok) {
            console.error('[map] class-layer fetch failed:', res.status);
            return;
        }
        const data = await res.json();
        const palette = this.hasClassPaletteValue ? this.classPaletteValue : {};
        this.classLayer = this.L.geoJSON(data, {
            // Stroke = the class colour: small dissolved patches are mostly stroke at park
            // zoom, and a fixed dark stroke would mute them (same lesson as the loss layer).
            // Same stroke/weight/opacity as the hub's loss layer, so the SAME dissolved
            // features render identically on the area dashboard and the module page.
            style: (f) => ({
                color: palette[f.properties.label] || '#888888', weight: 0.8,
                fillColor: palette[f.properties.label] || '#888888', fillOpacity: 0.85,
            }),
            onEachFeature: (f, layer) => layer.bindPopup(`<strong>${f.properties.label}</strong>`),
        }).addTo(this.map);
        this.classLayer.bringToBack(); // sit under the boundary casing/line
    }

    drawBoundary() {
        const data = this.boundaryValue;
        if (!data?.features?.length) {
            this.map.setView([-3.2, 35.5], 9);
            return;
        }
        // The platform's ONE boundary treatment (the map module's boundary.js): the
        // outside-the-area scrim, a white casing and the jade line - the same
        // three things, in the same order, that every module's maps draw.
        const boundary = drawBoundary(this.L, this.map, data);
        this.boundaryLayer = boundary;
        this.dimLayer = boundary.scrimLayer;
        this.map.fitBounds(boundary.getBounds(), { padding: [40, 40] });
    }


    async loadForestLoss() {
        const res = await fetch(this.forestLossUrlValue);
        if (!res.ok) {
            console.error('[map] forest-loss fetch failed:', res.status);
            return;
        }
        this.lossData = await res.json();
        this.renderForestLoss();
    }

    renderForestLoss() {
        if (this.lossLayer) {
            this.map.removeLayer(this.lossLayer);
        }
        const [lo, hi] = this.yearRange();
        this.lossLayer = this.L.geoJSON(this.lossData, {
            // The generic module layer labels each dissolved feature by its YEAR (a string).
            filter: (f) => Number(f.properties.label) >= lo && Number(f.properties.label) <= hi,
            style: (f) => {
                const year = Number(f.properties.label);
                // Hovering a chart bar spotlights that year; the rest fade back.
                const dimmed = this.hoveredYear != null && year !== this.hoveredYear;
                // Stroke = the year colour too: small patches are mostly stroke at park zoom,
                // so a fixed stroke colour would drown the ramp (it used to read all-red).
                return {
                    color: yearColor(year),
                    weight: dimmed ? 0.3 : 0.8,
                    fillColor: yearColor(year),
                    fillOpacity: dimmed ? 0.15 : 0.85,
                };
            },
            onEachFeature: (f, layer) => {
                layer.bindPopup(`<strong>${f.properties.label}</strong> — tree cover lost`);
            },
        }).addTo(this.map);
    }



    // Show only loss within the selected [from, to] year range; the slider fill,
    // the range summary, and the loss-by-year bars all track it live.
    filterYears() {
        const [lo, hi] = this.yearRange();
        const { YEAR_MIN, YEAR_MAX } = this.constructor;

        const span = YEAR_MAX - YEAR_MIN;
        this.rangeFillTarget.style.left = `${((lo - YEAR_MIN) / span) * 100}%`;
        this.rangeFillTarget.style.width = `${((hi - lo) / span) * 100}%`;

        let rangeHa = 0;
        this.barTargets.forEach((b) => {
            const y = Number(b.dataset.year);
            const inRange = y >= lo && y <= hi;
            b.classList.toggle('opacity-25', !inRange);
            if (inRange) {
                rangeHa += Number(b.dataset.ha) || 0;
            }
        });
        this.rangeSummaryTarget.textContent =
            `${lo}–${hi} · ${Math.round(rangeHa).toLocaleString()} ha`;

        if (this.lossData) {
            this.renderForestLoss();
        }
    }

    // Click a chart bar → snap the range to that single year.
    focusYear(event) {
        const year = event.currentTarget.dataset.year;
        this.fromYearTarget.value = year;
        this.toYearTarget.value = year;
        this.filterYears();
    }

    // Hover a chart bar → spotlight that year's polygons on the map.
    highlightYear(event) {
        this.hoveredYear = Number(event.currentTarget.dataset.year);
        if (this.lossData) {
            this.renderForestLoss();
        }
    }

    unhighlightYear() {
        this.hoveredYear = null;
        if (this.lossData) {
            this.renderForestLoss();
        }
    }

    yearRange() {
        if (!this.hasFromYearTarget) {
            return [2001, 2023];
        }
        const a = Number(this.fromYearTarget.value);
        const b = Number(this.toYearTarget.value);
        return [Math.min(a, b), Math.max(a, b)];
    }

    disconnect() {
        this.map?.remove();
    }
}
