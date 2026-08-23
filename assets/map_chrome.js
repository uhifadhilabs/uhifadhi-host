/*
 * The controls that sit on a map — ONCE, for the whole platform.
 *
 * Zoom ±, the DIM pill, a closed-by-default base-layer menu, fullscreen, a live
 * scale bar, and the Ctrl/⌘-scroll bargain. Every map in the app wears these,
 * in the same place, looking and behaving the same way: an area map and a module
 * map are the same instrument pointed at different data.
 *
 * IT BUILDS ITS OWN MARKUP. The alternative was the same five buttons and the
 * same menu written into a host template AND into each module bundle's Twig,
 * where they would drift apart the first time one repo was touched without the
 * other — and where a module's markup would have to know the host's class names
 * anyway. So the caller supplies a frame element and this fills it in; the
 * styles live in the host's app.css beside every other platform style.
 *
 * The buttons are real <button>s. They change what is on the map, so they must
 * be reachable by keyboard — and Stimulus binds no default event to a <b>, which
 * is exactly how the patrol chrome once shipped looking perfect and doing
 * nothing at all.
 *
 * Behaviour only: what is DRAWN on the map (boundary, tracks, overlays) is the
 * caller's business. See `uhifadhi/boundary` and `uhifadhi/basemaps`.
 */

const ICONS = {
    dim: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 18a6 6 0 0 0 0-12v12Z" fill="currentColor"/></svg>',
    layers: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/></svg>',
    fullscreen: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3"/><path d="M21 8V5a2 2 0 0 0-2-2h-3"/><path d="M3 16v3a2 2 0 0 0 2 2h3"/><path d="M16 21h3a2 2 0 0 0 2-2v-3"/></svg>',
};

/** ⌘ on a Mac, Ctrl everywhere else — the key people actually press. */
function modifierLabel() {
    return /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent) ? '⌘' : 'Ctrl';
}

function button({ label, title, html, text, pressed }) {
    const el = document.createElement('button');
    el.type = 'button';
    el.title = title ?? label;
    el.setAttribute('aria-label', label);
    if (pressed !== undefined) {
        el.setAttribute('aria-pressed', pressed ? 'true' : 'false');
        el.classList.toggle('on', Boolean(pressed));
    }
    if (html) {
        el.innerHTML = html;
    } else {
        el.textContent = text;
    }

    return el;
}

/**
 * Mount the chrome on `frame` (the element that also goes fullscreen, so any
 * legend or filter row inside it stays usable there).
 *
 * @param bases            {satellite, osm} Leaflet layers; the menu switches between them
 * @param scrim            the outside-the-area scrim layer, or null for no DIM control
 * @param scrimOn          whether the scrim starts switched on
 * @param fullscreenTarget what fullscreen expands, when it is wider than the frame
 *                         (a widget card, so its chips and legend come along)
 * @param onResize         called after the map is resized by a fullscreen change,
 *                         so the caller can re-frame what the map is about
 *
 * Returns { destroy() } — call it when the map goes away.
 */
export function mountMapChrome(L, map, frame, { bases = {}, scrim = null, scrimOn = false, fullscreenTarget = null, onResize = null } = {}) {
    // The chrome is positioned inside `frame`; fullscreen may take something
    // wider — the whole widget card, so its filter chips and legend come too.
    const expandTarget = fullscreenTarget ?? frame;
    const column = document.createElement('div');
    column.className = 'map-chrome';

    const zoomIn = button({ label: 'Zoom in', text: '+' });
    const zoomOut = button({ label: 'Zoom out', text: '−' });
    zoomIn.addEventListener('click', () => map.zoomIn());
    zoomOut.addEventListener('click', () => map.zoomOut());
    column.append(zoomIn, zoomOut);

    // The DIM pill exists only where there is something to dim.
    let dimBtn = null;
    if (scrim) {
        dimBtn = button({
            label: 'Dim outside the boundary',
            html: ICONS.dim,
            pressed: scrimOn,
        });
        dimBtn.addEventListener('click', () => {
            const on = map.hasLayer(scrim);
            if (on) {
                map.removeLayer(scrim);
            } else {
                scrim.addTo(map);
            }
            dimBtn.classList.toggle('on', !on);
            dimBtn.setAttribute('aria-pressed', on ? 'false' : 'true');
        });
        column.append(dimBtn);
    }

    // The base-layer menu, CLOSED until asked for: a menu that is always open is
    // not a menu, whatever the design file drew for convenience.
    const menu = document.createElement('div');
    menu.className = 'map-chrome-menu';
    menu.hidden = true;
    const layersBtn = button({ label: 'Base layer', title: 'Base layer: satellite or map', html: ICONS.layers });
    layersBtn.setAttribute('aria-expanded', 'false');

    const setMenu = (open) => {
        menu.hidden = !open;
        layersBtn.classList.toggle('on', open);
        layersBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    layersBtn.addEventListener('click', (event) => {
        event.stopPropagation();
        setMenu(menu.hidden);
    });

    const BASE_LABELS = { satellite: 'Satellite', osm: 'Map' };
    Object.keys(BASE_LABELS)
        .filter((name) => bases[name])
        .forEach((name, index) => {
            const choice = button({ label: BASE_LABELS[name], text: BASE_LABELS[name] });
            choice.classList.toggle('on', index === 0);
            choice.addEventListener('click', () => {
                Object.entries(bases).forEach(([other, layer]) => {
                    if (other === name) {
                        layer.addTo(map);
                    } else {
                        map.removeLayer(layer);
                    }
                });
                menu.querySelectorAll('button').forEach((b) => b.classList.toggle('on', b === choice));
                setMenu(false);
            });
            menu.append(choice);
        });
    column.append(layersBtn);

    const fullscreenBtn = button({
        label: 'Fullscreen',
        title: 'Fullscreen — the legend floats bottom-right',
        html: ICONS.fullscreen,
    });
    fullscreenBtn.addEventListener('click', () => {
        if (document.fullscreenElement) {
            document.exitFullscreen();
        } else if (expandTarget.requestFullscreen) {
            expandTarget.requestFullscreen().catch(() => {});
        }
    });
    column.append(fullscreenBtn);

    frame.append(column, menu);

    // A live scale bar: a map that zooms cannot wear a printed distance.
    const scaleControl = L.control.scale({ imperial: false, position: 'bottomright' }).addTo(map);
    map.attributionControl?.setPrefix(false);

    /*
     * Wheel zoom is OFF until Ctrl (⌘) is held: a map sits inside a scrolling
     * page, and scrolling past one must never zoom it instead. In full screen
     * there is no page behind the map, so a plain wheel zooms outright.
     */
    map.scrollWheelZoom.disable();
    let fullscreen = false;
    let hint = null;
    let hintTimer = null;

    const showHint = () => {
        if (!hint) {
            hint = document.createElement('span');
            hint.className = 'map-chrome-hint';
            hint.textContent = `Use ${modifierLabel()} + scroll to zoom the map`;
            frame.append(hint);
        }
        hint.classList.add('on');
        clearTimeout(hintTimer);
        hintTimer = setTimeout(() => hint?.classList.remove('on'), 1400);
    };

    const onWheel = (event) => {
        if (fullscreen) {
            return;
        }
        if (event.ctrlKey || event.metaKey) {
            map.scrollWheelZoom.enable();
            clearTimeout(hintTimer);
            hint?.classList.remove('on');
        } else {
            map.scrollWheelZoom.disable();
            showHint();
        }
    };
    // Arm it on the key press, not on the first wheel tick — otherwise the first
    // notch of a Ctrl+scroll is swallowed while the handler switches on.
    const onModifier = (event) => {
        if (fullscreen) {
            return;
        }
        if (event.ctrlKey || event.metaKey) {
            map.scrollWheelZoom.enable();
        } else {
            map.scrollWheelZoom.disable();
        }
    };
    const onFullscreen = () => {
        fullscreen = document.fullscreenElement?.contains(frame) ?? false;
        if (fullscreen) {
            map.scrollWheelZoom.enable();
        } else {
            map.scrollWheelZoom.disable();
        }
        map.invalidateSize();
        // The viewport just changed size by a lot. Keeping the old zoom would
        // show three times as much ground in full screen, shrinking the very
        // thing the map is about — so the caller re-frames its subject.
        onResize?.();
    };
    const onDocumentClick = (event) => {
        if (!menu.hidden && !menu.contains(event.target) && !layersBtn.contains(event.target)) {
            setMenu(false);
        }
    };
    const onKeydown = (event) => {
        if (event.key === 'Escape') {
            setMenu(false);
        }
        onModifier(event);
    };

    const canvas = map.getContainer();
    canvas.addEventListener('wheel', onWheel, { passive: true });
    document.addEventListener('keydown', onKeydown);
    document.addEventListener('keyup', onModifier);
    document.addEventListener('fullscreenchange', onFullscreen);
    document.addEventListener('click', onDocumentClick);

    return {
        dimButton: dimBtn,
        destroy() {
            canvas.removeEventListener('wheel', onWheel);
            document.removeEventListener('keydown', onKeydown);
            document.removeEventListener('keyup', onModifier);
            document.removeEventListener('fullscreenchange', onFullscreen);
            document.removeEventListener('click', onDocumentClick);
            clearTimeout(hintTimer);
            scaleControl.remove();
            column.remove();
            menu.remove();
            hint?.remove();
        },
    };
}
