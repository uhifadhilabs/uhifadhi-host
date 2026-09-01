import { Controller } from '@hotwired/stimulus';
import { satelliteLayer, streetLayer } from 'uhifadhi/basemaps';
import { drawBoundary } from 'uhifadhi/boundary';
import { mountMapChrome } from 'uhifadhi/map-chrome';

/*
 * THE AREA OVERVIEW'S OPERATIONAL PLATE — one map, many owners.
 *
 * The host draws the plate and every layer on it belongs to the module that owns
 * the data: the boundary and the zones are the host's, today's tracks and the
 * live positions are the patrols module's, the open incidents are the incidents
 * module's. They all arrive here the same way — a GeoJSON FeatureCollection with
 * a swatch, a style and whether it is on — because the host must not know what
 * any of them mean (Uhifadhi\Overview\MapLayer).
 *
 * EVERY LAYER SHIPS A LEGEND, and the legend is the switch: each entry carries
 * the layer's id and toggling it adds or removes exactly that layer. The two
 * scientific layers are in the list and OFF — demoted, never deleted.
 *
 * Leaflet, self-hosted (window.L), like every other map in the product. Not
 * MapLibre: this is raster tiles plus GeoJSON overlays, which Leaflet does in
 * plain DOM/SVG with no WebGL2 requirement.
 */
export default class extends Controller {
    static values = { layers: Array };
    static targets = ['canvas', 'frame', 'pincard'];

    connect() {
        const L = window.L;
        if (!L) {
            console.error('[overview-map] window.L (Leaflet) is not loaded — check the <script> tag');
            return;
        }
        // A cached Turbo snapshot is not a page: building a map into one leaves a
        // map that is thrown away and a container Leaflet then refuses to reuse.
        if (this.element.closest('[data-turbo-preview]')) return;
        this.L = L;

        // THE LIBRARY RENDERS EVERY WIDGET, including this one, into a stage that
        // is scaled and may have no size at all when the controller connects. A
        // vector renderer with no bounds never draws and Leaflet throws on the
        // deferred layer queue, so the build waits for layout rather than
        // happening into nothing.
        if (this.canvasTarget.offsetWidth && this.canvasTarget.offsetHeight) {
            this.build();

            return;
        }
        this.observer = new ResizeObserver(() => {
            if (!this.canvasTarget.offsetWidth || !this.canvasTarget.offsetHeight) return;
            this.observer.disconnect();
            this.observer = null;
            this.build();
        });
        this.observer.observe(this.canvasTarget);
    }

    build() {
        const L = this.L;
        this.map = L.map(this.canvasTarget, { zoomControl: false });
        this.canvasTarget.classList.add('map-chrome-host');
        this.map.setView([0, 0], 2); // a view before any vector layer: Leaflet 1.9 needs one

        this.bases = { osm: streetLayer(L), satellite: satelliteLayer(L, this.map) };
        this.bases.satellite.addTo(this.map);

        this.layers = new Map();
        this.drawLayers();

        // The DIM pill needs the scrim the boundary layer built, so the chrome
        // goes on after the layers rather than before them.
        this.chrome = mountMapChrome(L, this.map, this.canvasTarget.parentElement, {
            bases: this.bases,
            scrim: this.scrim,
            scrimOn: Boolean(this.scrim) && this.map.hasLayer(this.scrim),
            fullscreenTarget: this.hasFrameTarget ? this.frameTarget : this.canvasTarget.parentElement,
            onResize: () => this.frame(),
        });

        this.frame();
    }

    disconnect() {
        this.observer?.disconnect();
        this.observer = null;
        this.chrome?.destroy();
        this.chrome = null;
        this.layers?.clear();
        this.layers = null;
        if (this.map) {
            this.map.stop();
            this.map.remove();
            this.map = null;
        }
    }

    /* One Leaflet layer per contributed layer, kept by id so the legend can
     * switch exactly one of them without re-reading anything. */
    drawLayers() {
        this.layersValue.forEach((def) => this.addLayer(def));
    }

    addLayer(def) {
        // THE AREA'S OWN OUTLINE IS NOT A GENERIC LAYER. It goes through the
        // platform's one boundary definition — white casing, jade line, and the
        // scrim that turns the area into figure and the rest of the world into
        // ground — so this plate reads exactly like every other map in the
        // product rather than drawing its own 2px line.
        if (def.style === 'boundary') {
            const boundary = drawBoundary(this.L, this.map, def.features, { scrim: def.on });
            if (boundary) {
                this.layers.set(def.id, boundary);
                this.scrim = boundary.scrimLayer;
                if (!def.on) this.map.removeLayer(boundary);
            }

            return;
        }

        {
            const line = def.style === 'line';
            const layer = this.L.geoJSON(def.features, {
                style: () => ({
                    color: def.swatch,
                    weight: line ? 2 : 1.2,
                    opacity: 0.95,
                    fill: !line,
                    fillColor: def.swatch,
                    fillOpacity: line ? 0 : 0.18,
                }),
                // A point layer draws as a circle marker: a pin's colour is its
                // module's swatch, so two layers can never be confused.
                pointToLayer: (feature, latlng) => this.L.circleMarker(latlng, {
                    radius: 6,
                    color: 'rgba(10,14,11,.85)',
                    weight: 1.4,
                    fillColor: def.swatch,
                    fillOpacity: 1,
                }),
                onEachFeature: (feature, leafletLayer) => {
                    const p = feature.properties || {};
                    const title = p.ref || p.title || p.name;
                    if (title) leafletLayer.bindTooltip(p.detail ? `${title} · ${p.detail}` : String(title));
                    // The docked plate names the hovered feature ON the imagery,
                    // from the layer's own properties. Nothing here knows what a
                    // patrol or an incident is.
                    if (!this.hasPincardTarget) return;
                    leafletLayer.on('mouseover', () => this.showPin(p, def));
                    leafletLayer.on('mouseout', () => { this.pincardTarget.hidden = true; });
                },
            });

            this.layers.set(def.id, layer);
            if (def.on) layer.addTo(this.map);
        }
    }

    /* THE ONE POLLER HANDS THE MAP ITS LIVE LAYERS. The map redraws exactly the
     * layers it was given, keeping each one's on/off state, and knows nothing
     * about who fetched them or how often.
     * → overview_live_controller.js, event `overview:layers` */
    adopt(event) {
        if (!this.map) return;
        (event.detail?.layers || []).forEach((fresh) => {
            const def = this.layersValue.find((l) => l.id === fresh.id);
            const old = this.layers.get(fresh.id);
            if (!def || !old) return;
            const wasOn = this.map.hasLayer(old);
            this.map.removeLayer(old);
            this.layers.delete(fresh.id);
            this.addLayer({ ...def, features: fresh.features, on: wasOn });
        });
    }

    showPin(p, def) {
        const card = this.pincardTarget;
        card.querySelector('.id').textContent = p.ref || p.name || def.id;
        card.querySelector('.tt').textContent = p.title || '';
        card.querySelector('.mt').textContent = [p.place, p.when, p.detail].filter(Boolean).join(' · ');
        card.hidden = false;
    }

    /* The legend IS the switch. → the `lay` entries in _legend.html.twig */
    toggle(event) {
        event.preventDefault();
        const entry = event.currentTarget;
        const layer = this.layers?.get(entry.dataset.layerId);
        if (!layer) return;

        const on = !entry.classList.contains('off');
        entry.classList.toggle('off', on);
        const state = entry.querySelector('.tog');
        if (state) state.textContent = on ? 'off' : 'on';

        if (on) this.map.removeLayer(layer);
        else layer.addTo(this.map);
    }

    /* Frame the area on whatever is drawn, so the plate opens on the place
     * rather than on the world. */
    frame() {
        if (!this.map) return;
        let bounds = null;
        this.layers.forEach((layer) => {
            if (!this.map.hasLayer(layer)) return;
            const b = layer.getBounds();
            if (!b.isValid()) return;
            bounds = bounds ? bounds.extend(b) : b;
        });
        if (bounds) this.map.fitBounds(bounds, { padding: [40, 40] });
    }
}
