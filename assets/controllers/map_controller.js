import { Controller } from '@hotwired/stimulus';

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

// Hansen GFC year ramp (YlOrRd), 2001 → 2023.
const RAMP = [
    [2001, [0xff, 0xff, 0xb2]],
    [2008, [0xfe, 0xcc, 0x5c]],
    [2014, [0xfd, 0x8d, 0x3c]],
    [2019, [0xf0, 0x3b, 0x20]],
    [2023, [0xbd, 0x00, 0x26]],
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
    static values = { boundary: Object, forestLossUrl: String };
    static targets = ['canvas', 'fromYear', 'toYear', 'bar', 'rangeFill', 'rangeSummary'];

    static YEAR_MIN = 2001;
    static YEAR_MAX = 2023;

    connect() {
        const L = window.L;
        if (!L) {
            console.error('[map] window.L (Leaflet) is not loaded — check the <script> tag');
            return;
        }
        this.L = L;

        // Zoom top-right: the control panel owns the top-left corner.
        this.map = L.map(this.canvasTarget, { zoomControl: false });
        L.control.zoom({ position: 'topright' }).addTo(this.map);
        L.control.scale({ imperial: false, position: 'bottomright' }).addTo(this.map);
        this.map.attributionControl.setPrefix(false);

        this.bases = {
            osm: L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '© OpenStreetMap contributors',
            }),
            satellite: L.tileLayer(
                'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
                { maxZoom: 19, attribution: 'Esri, Maxar, Earthstar Geographics' },
            ),
        };
        this.bases.satellite.addTo(this.map); // satellite is the default base

        this.drawBoundary();
        this.loadForestLoss();
    }

    drawBoundary() {
        const data = this.boundaryValue;
        if (!data?.features?.length) {
            this.map.setView([-3.2, 35.5], 9);
            return;
        }
        // White casing under a green line so the boundary reads on any base.
        this.L.geoJSON(data, { style: { color: '#ffffff', weight: 6, opacity: 0.9, fill: false } }).addTo(this.map);
        const line = this.L.geoJSON(data, { style: { color: '#2e7d32', weight: 3, fill: false } }).addTo(this.map);
        this.map.fitBounds(line.getBounds(), { padding: [40, 40] });
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
            filter: (f) => f.properties.year >= lo && f.properties.year <= hi,
            style: (f) => {
                // Hovering a chart bar spotlights that year; the rest fade back.
                const dimmed = this.hoveredYear != null && f.properties.year !== this.hoveredYear;
                return {
                    color: '#7f0000',
                    weight: dimmed ? 0.3 : 0.5,
                    fillColor: yearColor(f.properties.year),
                    fillOpacity: dimmed ? 0.15 : 0.85,
                };
            },
            onEachFeature: (f, layer) => {
                const ha = Math.round(Number(f.properties.areaHa) || 0).toLocaleString();
                layer.bindPopup(`<strong>${f.properties.year}</strong><br>${ha} ha lost`);
            },
        }).addTo(this.map);
    }

    // Toggle the OSM / satellite base layer.
    showBase(event) {
        const base = event.currentTarget.dataset.base;
        Object.entries(this.bases).forEach(([name, layer]) => {
            if (name === base) {
                layer.addTo(this.map);
            } else {
                this.map.removeLayer(layer);
            }
        });
        event.currentTarget.parentElement.querySelectorAll('button').forEach((b) => {
            const on = b === event.currentTarget;
            b.classList.toggle('bg-forest-500', on);
            b.classList.toggle('text-white', on);
        });
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
