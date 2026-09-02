import { Controller } from '@hotwired/stimulus';
import { satelliteLayer, streetLayer } from 'uhifadhi/basemaps';
import { drawBoundary } from 'uhifadhi/boundary';
import { mountMapChrome } from 'uhifadhi/map-chrome';

/*
 * The ZONES layer, on the platform's own map.
 *
 * Everything here except the zone polygons is borrowed, on purpose: the imagery from
 * `uhifadhi/basemaps`, the AOI outline and its outside-the-area scrim from `uhifadhi/boundary`,
 * and zoom / DIM / base-layer / fullscreen from `uhifadhi/map-chrome`. An area map, a module map
 * and this map are the same instrument pointed at different data, so this controller adds exactly
 * one thing to it — the zones.
 *
 * DRAW ORDER IS THE DESIGN: zones are tinted UNDER the boundary, never over it. The AOI line is
 * what tells you where the area ends, and a zone that ran across it would hide the one edge that
 * is not negotiable.
 *
 * COLOUR IS DATA, NOT THEME. Not one hex lives in this file: every polygon wears the `color` its
 * own GeoJSON feature carries (Uhifadhi\Model\ZonePalette, served by the zones.geojson endpoint),
 * which is the same value the legend beside the map and the swatch in the list read. A palette
 * change therefore happens in one PHP file and sweeps every place a zone is drawn.
 *
 * Leaflet, self-hosted UMD → window.L, exactly as the area map: raster tiles plus GeoJSON overlays
 * in plain DOM/SVG. Do not reintroduce MapLibre (see HANDOVER §3).
 */

/** Zones are a tint you read the imagery through, not a paint that hides it. */
const FILL_OPACITY = 0.34;
const SELECTED_FILL_OPACITY = 0.58;

export default class extends Controller {
    static values = {
        geojsonUrl: String,
        boundary: Object,
        selected: String,
    };

    static targets = ['canvas', 'frame'];

    connect() {
        const L = window.L;
        if (!L) {
            console.error('[zone-map] window.L (Leaflet) is not loaded — check the <script> tag');
            return;
        }
        this.L = L;

        this.map = L.map(this.canvasTarget, {
            zoomControl: false,
            attributionControl: true,
            // The chrome offers zoom buttons and the Ctrl/⌘-scroll bargain; a bare wheel must not
            // swallow a page scroll on a widget the size of half a row.
            scrollWheelZoom: false,
        });
        // The platform mark: it isolates Leaflet's panes so the chrome mounted beside them stays
        // visible, and it dresses Leaflet's own scale bar, attribution and tooltips as the same
        // overlay pills every other map wears. → the .map-chrome-host block in app.css
        this.canvasTarget.classList.add('map-chrome-host');
        // Leaflet refuses to take a layer before it has a view, and the real framing needs the
        // boundary that is about to be drawn — so open on the world and fit once there is a shape.
        this.map.setView([0, 0], 2);

        const bases = {
            satellite: satelliteLayer(L, this.map),
            osm: streetLayer(L),
        };
        bases.satellite.addTo(this.map);

        // Zones go on first so the boundary drawn above them stays legible.
        this.zoneLayer = L.layerGroup().addTo(this.map);

        // The boundary and its scrim, from the platform module — the same green line, the same dim,
        // as every other map in the app. drawBoundary returns ONE layer and hangs the scrim off it
        // as `.scrimLayer` (the scrim covers the world, so it must never be in the bounds).
        this.boundary = this.hasBoundaryValue && this.boundaryValue?.type
            ? drawBoundary(L, this.map, this.boundaryValue, { scrim: true })
            : null;
        this.boundary?.bringToFront();

        this.chrome = mountMapChrome(L, this.map, this.hasFrameTarget ? this.frameTarget : this.element, {
            bases,
            scrim: this.boundary?.scrimLayer ?? null,
            scrimOn: Boolean(this.boundary?.scrimLayer),
            fullscreenTarget: this.element,
            onResize: () => this.#frame(),
        });

        this.#load();
    }

    disconnect() {
        this.chrome?.destroy();
        this.map?.remove();
        this.map = null;
    }

    async #load() {
        let document;
        try {
            const response = await fetch(this.geojsonUrlValue, { headers: { Accept: 'application/geo+json' } });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            document = await response.json();
        } catch (error) {
            console.error('[zone-map] could not load the zone layer', error);
            return;
        }
        // The element can be gone by the time the fetch lands (a Turbo navigation mid-flight).
        if (!this.map) {
            return;
        }

        (document.features ?? []).forEach((feature) => this.#drawZone(feature));
        this.#frame();
    }

    #drawZone(feature) {
        const L = this.L;
        const { color, name, plate, areaKm2, sharePct, uuid } = feature.properties ?? {};
        const isSelected = Boolean(this.selectedValue) && uuid === this.selectedValue;

        const polygon = L.geoJSON(feature, {
            style: {
                color,
                weight: isSelected ? 3 : 1.6,
                opacity: 0.9,
                fillColor: color,
                fillOpacity: isSelected ? SELECTED_FILL_OPACITY : FILL_OPACITY,
                lineJoin: 'round',
            },
        });

        // A polygon that answers "which zone is this?" without a click, and the numbers on hover —
        // the same two facts the list beside it shows.
        polygon.bindTooltip(
            `${name} · ${plate}`,
            { permanent: true, direction: 'center', className: 'zone-label', opacity: 1 },
        );
        polygon.bindPopup(
            `<b>${name}</b><br>${Number(areaKm2).toLocaleString()} km² · ${sharePct}% of the AOI`,
        );

        polygon.addTo(this.zoneLayer);
        if (isSelected) {
            this.selectedBounds = polygon.getBounds();
        }
        // Zones sit UNDER the boundary line, always.
        polygon.bringToBack();
    }

    /**
     * Frame the selected zone if there is one, else the whole area. An area with no boundary keeps
     * the world view it opened on rather than throwing.
     */
    #frame() {
        const bounds = this.selectedBounds ?? this.boundary?.getBounds();
        if (bounds?.isValid?.()) {
            this.map.fitBounds(bounds, { padding: [18, 18] });
        }
    }
}
