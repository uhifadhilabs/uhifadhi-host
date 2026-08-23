/*
 * The platform's basemap sources, defined ONCE so every map — the host's area
 * maps and every module's maps — shows the same imagery. A layer must look the
 * same wherever it is drawn (the map-legend rule), and that starts with the
 * ground it is drawn on.
 *
 * SATELLITE is Google's official Map Tiles API. That API serves XYZ tiles only
 * against a session token from a createSession call — "The session token is
 * valid for two weeks from its creation time":
 *   https://developers.google.com/maps/documentation/tile/session_tokens
 *   https://developers.google.com/maps/documentation/tile/roadmap
 * The token is cached in sessionStorage for the tab, so a person browsing the
 * app makes one createSession call, not one per map and not one per page.
 *
 * The key is published on <body data-google-maps-api-key> by the host's
 * base.html.twig (from UHIFADHI_GOOGLE_MAPS_API_KEY). It is public by nature —
 * it travels inside every tile URL — so it must be HTTP-referrer restricted at
 * Google, exactly like a Maps JS key.
 *
 * NO KEY, NO PROBLEM: with an empty key, a failed createSession or an offline
 * browser, satellite silently stays on the keyless Esri layer. A map must never
 * break for want of a key.
 */

const CREATE_SESSION_URL = 'https://tile.googleapis.com/v1/createSession';
const TILE_URL = 'https://tile.googleapis.com/v1/2dtiles/{z}/{x}/{y}';
const SESSION_STORAGE_KEY = 'uhifadhi.google_tile_session';
/* Renew a day before Google expires the token, never trusting it to the wire. */
const EXPIRY_MARGIN_MS = 86400 * 1000;

export const OSM_TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
export const OSM_ATTRIBUTION = '© OpenStreetMap contributors';

/* The keyless fallback: what every map used before the Map Tiles key existed. */
export const ESRI_TILES =
    'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';
export const ESRI_ATTRIBUTION = 'Esri, Maxar, Earthstar Geographics';

/* Google requires its attribution to be shown alongside its imagery. */
export const GOOGLE_ATTRIBUTION = '© Google';

export function googleMapsApiKey() {
    return document.body?.dataset?.googleMapsApiKey ?? '';
}

/**
 * A tile URL template for Google's 2D satellite tiles, or null when there is no
 * key or the session could not be created.
 */
export async function googleTileTemplate() {
    const key = googleMapsApiKey();
    if (!key) {
        return null;
    }

    const session = await sessionToken(key);

    return session ? `${TILE_URL}?session=${encodeURIComponent(session)}&key=${encodeURIComponent(key)}` : null;
}

async function sessionToken(key) {
    const cached = readCachedSession();
    if (cached) {
        return cached;
    }

    try {
        // Required body fields per the createSession reference; mapType picks the
        // satellite imagery.
        const response = await fetch(`${CREATE_SESSION_URL}?key=${encodeURIComponent(key)}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ mapType: 'satellite', language: 'en-US', region: 'US' }),
        });
        if (!response.ok) {
            return null;
        }
        const payload = await response.json();
        if (typeof payload?.session !== 'string' || payload.session === '') {
            return null;
        }
        // `expiry` is seconds-since-epoch, as a string.
        const expiresAt = Number(payload.expiry) * 1000 - EXPIRY_MARGIN_MS;
        cacheSession(payload.session, expiresAt);

        return payload.session;
    } catch {
        return null; // offline, blocked key, CORS — fall back, never break
    }
}

function readCachedSession() {
    try {
        const raw = window.sessionStorage?.getItem(SESSION_STORAGE_KEY);
        if (!raw) {
            return null;
        }
        const { session, expiresAt } = JSON.parse(raw);

        return typeof session === 'string' && Date.now() < expiresAt ? session : null;
    } catch {
        return null;
    }
}

function cacheSession(session, expiresAt) {
    try {
        window.sessionStorage?.setItem(SESSION_STORAGE_KEY, JSON.stringify({ session, expiresAt }));
    } catch {
        /* private mode / storage disabled: just make the call again next time */
    }
}

/**
 * The street base layer. One definition, every map.
 */
export function streetLayer(L, { maxZoom = 19 } = {}) {
    return L.tileLayer(OSM_TILES, { maxZoom, attribution: OSM_ATTRIBUTION });
}

/**
 * The satellite base layer: it starts on the keyless Esri imagery so the map is
 * never blank, then upgrades itself to Google Map Tiles (and Google's
 * attribution) as soon as the session resolves. With no key it simply stays on
 * Esri.
 *
 * `map` is passed in so the attribution can be swapped through Leaflet's public
 * control API rather than reaching into the layer's private `_map`.
 */
export function satelliteLayer(L, map, { maxZoom = 19 } = {}) {
    const layer = L.tileLayer(ESRI_TILES, { maxZoom, attribution: ESRI_ATTRIBUTION });

    googleTileTemplate().then((template) => {
        if (!template) {
            return;
        }
        layer.options.attribution = GOOGLE_ATTRIBUTION;
        if (map?.attributionControl) {
            map.attributionControl.removeAttribution(ESRI_ATTRIBUTION);
            // Leaflet only shows the attribution of layers currently on the map.
            if (map.hasLayer(layer)) {
                map.attributionControl.addAttribution(GOOGLE_ATTRIBUTION);
            }
        }
        layer.setUrl(template);
    });

    return layer;
}
