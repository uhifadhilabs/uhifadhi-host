/* THE PRESET COMPONENT's behaviour — ONCE, for the whole platform.
 *
 * One widget library, identical on every surface that ships a WidgetCatalog:
 * departments, team, a department's detail and performance tabs, an area's
 * zones, a module's dashboard. A page instantiates it by including
 * templates/widgets/_library.html.twig; nothing below knows which dashboard it
 * is arranging, and a surface never varies it.
 *
 * THE MODEL (Uhifadhi\Service\WidgetService) — there is NO ANONYMOUS LAYOUT. A
 * dashboard renders exactly ONE ACTIVE PRESET: a design the surface ships, or
 * one the person saved. So:
 *
 *   * The strip always has exactly one card wearing "Active", and a fresh person
 *     starts on the surface's default built-in.
 *   * BUILT-INS ARE IMMUTABLE. The composition controls are not offered while
 *     one is active — the toolbar offers "Make a copy to customize", and the
 *     copy is what gets edited. Nothing forks behind anyone's back.
 *   * EDITING A CUSTOM PRESET WRITES THROUGH to it, in place. It stays active,
 *     its card's count updates, and there is no separate "save my layout" step.
 *   * CLICKING A CARD THAT IS NOT ACTIVE PREVIEWS IT: the canvas below becomes
 *     exactly the dashboard that preset produces. Preview is client-side only —
 *     it re-composes from the catalogue this page embedded and clones the widget
 *     renders it embedded, so it costs no round trip.
 *
 * PROGRESSIVE ENHANCEMENT. The page renders the active preset's strips, toolbar
 * and canvas server-side and every action there is a plain form post; what this
 * file adds is preview, the picker's live filtering, and optimistic editing.
 * Plain DOM inside, no framework requirement beyond the import: a module bundle
 * must not have to install a Stimulus controller before someone can arrange
 * their own dashboard.
 *
 * The DOM is the state. Every edit re-reads the whole canvas and posts the
 * COMPLETE layout, so a dropped request can never leave a half-applied one.
 *
 * Every write carries the CSRF token the page rendered, in the header the
 * endpoint reads (WidgetDom::CSRF_HEADER). The names below are asserted against
 * the PHP constants by tests/Unit/WidgetLibraryAssetsTest, because a mismatch
 * here fails ONLY in a browser: every server-side test builds its own header and
 * would still pass. That is exactly how a silent 403 shipped once already.
 */

export const CSRF_HEADER = 'X-CSRF-Token';

/* The wire, mirroring Uhifadhi\Model\WidgetDom one string at a time. */
export const ATTR = Object.freeze({
    root: 'data-widget-root',
    csrfToken: 'data-widget-csrf-token',
    saveUrl: 'data-widget-save-url',
    resetUrl: 'data-widget-reset-url',
    presetUrl: 'data-widget-preset-url',
    presetCopyUrl: 'data-widget-preset-copy-url',
    presetsUrl: 'data-widget-presets-url',
    presetApplyUrl: 'data-widget-preset-apply-url',
    presetRenameUrl: 'data-widget-preset-rename-url',
    presetDeleteUrl: 'data-widget-preset-delete-url',
    catalog: 'data-widget-catalog',
    template: 'data-widget-template',
    notice: 'data-widget-notice',
    reset: 'data-widget-reset',
    widget: 'data-widget-id',
    on: 'data-widget-on',
    cols: 'data-widget-cols',
    grip: 'data-widget-grip',
    toggle: 'data-widget-toggle',
    toggleLabel: 'data-widget-toggle-label',
    span: 'data-widget-span',
    chosen: 'data-widget-chosen',
    preview: 'data-widget-preview',
});

export const ROOT_SELECTOR = '[' + ATTR.root + ']';

/* Keep in step with .w-sliding in assets/styles/app.css. */
const SLIDE_MS = 160;

/* As long as a preset name may be — Uhifadhi\Service\WidgetService::NAME_MAX. */
const NAME_MAX = 60;

/* The width every widget's markup is laid out at inside a picker stage. A
 * dashboard-ish width, so a widget composes the way it will on the page; the
 * stage then scales the whole thing down to fit. Stated once, because the code
 * below measures against it to compute that scale. */
const PICK_STAGE_WIDTH = 1180;

/* The width chips read as fractions of the row, not as column counts. */
const SPAN_LABELS = { 12: '1', 9: '¾', 6: '½', 3: '¼' };

/* Roots already armed. Held here rather than in an attribute on the element:
 * the attribute contract above is the WIRE to the server, and a private
 * bookkeeping flag has no business in it. */
const armed = new WeakSet();

/**
 * Arm the library at `root` (default: the one root on the page). Returns false
 * where there is nothing to arm, so a page without a library costs nothing and a
 * second call (a Turbo re-visit, a lazily mounted controller) is harmless.
 */
export function initWidgetLibrary(root = document.querySelector(ROOT_SELECTOR)) {
    if (!root || armed.has(root)) {
        return false;
    }

    const catalogEl = root.querySelector('[' + ATTR.catalog + ']');
    if (!catalogEl) {
        return false;
    }

    let def;
    try {
        def = JSON.parse(catalogEl.textContent);
    } catch (e) {
        return false;
    }
    armed.add(root);

    /* ---- the catalogue, read ------------------------------------------- */

    function widgetOf(id) {
        return def.widgets.filter((w) => w.id === id)[0] || null;
    }

    function groupOf(id) {
        return def.groups.filter((g) => g.id === id)[0] || null;
    }

    function presetFor(kind, id) {
        const list = 'mine' === kind ? def.mine : def.builtins;

        return list.filter((p) => p.id === id)[0] || null;
    }

    /**
     * A layout as a full ordered composition. Listed is ON, in that order, at
     * that width; anything absent is simply not there. TOLERANT of an id the
     * catalogue no longer ships and of a span it no longer offers — a saved
     * preset must survive the catalogue growing or shrinking, exactly as
     * WidgetService::presetPayload() is on the server.
     */
    function compose(layout) {
        const entries = [];
        Object.keys(layout || {}).forEach((id) => {
            const w = widgetOf(id);
            if (!w) {
                return;
            }
            entries.push({ id: id, cols: w.spans.indexOf(layout[id]) >= 0 ? layout[id] : w.cols });
        });

        return entries;
    }

    /* ---- what the library is looking at --------------------------------- *
     * The previewed preset if there is one, the ACTIVE preset otherwise. One
     * object, so every part below asks the same question once.
     * --------------------------------------------------------------------- */

    let preview = null;

    function selection() {
        if (preview) {
            return {
                kind: preview.kind,
                id: preview.id,
                label: preview.label,
                isActive: false,
                activeLabel: def.active.label,
                /* Editing is offered ONLY where it is allowed. Previewing is
                 * looking, not editing; a built-in is never editable at all. */
                editable: 'new' === preview.kind,
                entries: compose(preview.layout),
            };
        }

        const active = presetFor(def.active.kind, def.active.id);

        return {
            kind: def.active.kind,
            id: def.active.id,
            label: def.active.label,
            isActive: true,
            activeLabel: def.active.label,
            editable: 'mine' === def.active.kind,
            entries: compose(active ? active.layout : {}),
        };
    }

    /* ---- the notice ------------------------------------------------------ */

    const notice = root.querySelector('[' + ATTR.notice + ']');

    function showNotice(message) {
        if (notice) {
            notice.textContent = message;
            notice.removeAttribute('hidden');
        }
    }

    function hideNotice() {
        if (notice) {
            notice.setAttribute('hidden', 'hidden');
        }
    }

    /* ---- the wire -------------------------------------------------------- */

    function csrfToken() {
        return root.getAttribute(ATTR.csrfToken) || '';
    }

    function postHeaders(extra) {
        const headers = extra || {};
        headers[CSRF_HEADER] = csrfToken();

        return headers;
    }

    /* A route template with WidgetDom::ID_PLACEHOLDER where the id goes. The
     * component draws cards that did not exist when the page was rendered, so it
     * BUILDS their URLs rather than reading an href off one. */
    function url(attribute, id) {
        const template = root.getAttribute(attribute) || '';

        return undefined === id ? template : template.replace(def.placeholder, encodeURIComponent(id));
    }

    function failure(response) {
        return 403 === response.status
            ? 'Your session has expired, so that change was not saved. Reload the page and try again.'
            : 'That change could not be saved (error ' + response.status + '). Your dashboard is unchanged.';
    }

    /* A COMMIT — a write that changes which preset the dashboard shows, or what
     * one holds. The server is the source of truth about presets (it names the
     * copy, it decides what a delete falls back to), so a commit that lands
     * re-reads the page rather than guessing the new state here. */
    function commit(target, body) {
        hideNotice();

        return fetch(target, {
            method: 'POST',
            credentials: 'same-origin',
            headers: postHeaders(body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
            body: body instanceof FormData ? body : JSON.stringify(body),
        }).then((response) => {
            if (!response.ok) {
                showNotice(failure(response));

                return;
            }
            window.location.reload();
        }).catch(() => {
            showNotice('That change could not be saved — you appear to be offline. Your dashboard is unchanged.');
        });
    }

    function tokenBody(extra) {
        const form = new FormData();
        form.append('_token', csrfToken());
        Object.keys(extra || {}).forEach((key) => form.append(key, extra[key]));

        return form;
    }

    /* ---- rendering ------------------------------------------------------- *
     * The strips, the toolbar and the canvas are re-drawn from the catalogue as
     * the selection changes. The page rendered exactly this for the ACTIVE
     * preset; what follows is the same markup for whichever preset is being
     * looked at, so a preview and the real thing read identically.
     * --------------------------------------------------------------------- */

    function countLine(layout) {
        const count = Object.keys(layout || {}).length;

        return count + ' widget' + (1 === count ? '' : 's');
    }

    function presetCard(opts) {
        let flag = '';
        if (opts.active) {
            flag = '<span class="w-presetflag w-presetflag-active">' + icon('check') + 'Active</span>';
        } else if (opts.selected) {
            flag = '<span class="w-presetflag w-presetflag-sel">' + icon('eye')
                + (opts.selectedLabel || 'Previewing') + '</span>';
        }

        return '<div class="w-preset'
            + (opts.active ? ' w-preset-active' : '')
            + (opts.selected ? ' w-preset-on' : '')
            + (opts.extraClass || '') + '"'
            + ' data-preset-kind="' + opts.kind + '" data-preset-id="' + escapeHtml(opts.id || '') + '"'
            + ' role="button" tabindex="0" aria-pressed="' + (opts.selected || opts.active ? 'true' : 'false') + '">'
            + '<div class="w-presettop">'
            + '<span class="w-presetname">' + escapeHtml(opts.label) + '</span>'
            + flag
            + '</div>'
            /* The whole line, wrapped — a trade-off cut off mid-sentence is worse
             * than no trade-off. The grid row equalises the cards' heights. */
            + '<p class="w-presetsub">' + escapeHtml(opts.description) + '</p>'
            + '<div class="w-presetmeta">'
            + '<span class="w-presetcount">' + countLine(opts.layout) + '</span>'
            + '<span class="w-presetgo">' + (opts.active ? 'On your dashboard' : 'Preview') + icon('arrow-right') + '</span>'
            + '</div>'
            + '</div>';
    }

    function designStrip(sel) {
        return def.builtins.map((p) => presetCard({
            kind: 'design',
            id: p.id,
            label: p.label,
            description: p.description,
            layout: p.layout,
            active: 'design' === sel.kind && sel.id === p.id && sel.isActive,
            selected: 'design' === sel.kind && sel.id === p.id && !sel.isActive,
        })).join('');
    }

    function mineStrip(sel) {
        const composing = 'new' === sel.kind;
        let cards = presetCard({
            kind: 'new',
            id: '',
            extraClass: ' w-presetnew',
            label: composing ? 'New preset' : '+ New preset',
            description: composing
                ? 'Add widgets below and set their widths — this card fills as you compose. Then name it and save.'
                : 'Start from an empty canvas and compose exactly the dashboard you want.',
            layout: composing ? layoutOfCanvas() : {},
            active: false,
            selected: composing,
            selectedLabel: 'Composing',
        });

        cards += def.mine.map((p) => presetCard({
            kind: 'mine',
            id: p.id,
            label: p.label,
            description: p.description,
            layout: p.layout,
            active: 'mine' === sel.kind && sel.id === p.id && sel.isActive,
            selected: 'mine' === sel.kind && sel.id === p.id && !sel.isActive,
        })).join('');

        return cards;
    }

    /**
     * THE TOOLBAR — the one stable place every action lives, whatever is
     * selected. One horizontal row: a quiet status line, then the house pairing
     * (one primary .cta, quiet .tgl secondaries) at the house control scale. A
     * card never grows a form, because growing one would reflow the whole strip
     * under the cursor that just clicked it.
     */
    function toolbar(sel) {
        let status;
        let acts;

        if ('new' === sel.kind) {
            status = 'Composing a <b>new preset</b>. Nothing is saved until you name it and save.';
            acts = '<form class="w-baracts" data-preset-create>'
                + '<input class="w-presetnameinput" type="text" name="name" required maxlength="' + NAME_MAX + '"'
                + ' placeholder="Name this preset" aria-label="Name this preset">'
                + '<button type="submit" class="cta w-presetapply">' + icon('bookmark') + 'Save preset</button>'
                + '<button type="button" class="tgl" data-preset-cancel>Cancel</button>'
                + '</form>';
        } else if ('design' === sel.kind) {
            status = sel.isActive
                ? 'Your dashboard shows <b>' + escapeHtml(sel.label) + '</b>. It is one of the product’s own designs, so it cannot be edited.'
                : 'Previewing <b>' + escapeHtml(sel.label) + '</b>. Your dashboard still shows <b>' + escapeHtml(sel.activeLabel) + '</b>.';
            acts = '<div class="w-baracts">'
                + (sel.isActive ? ''
                    : '<button type="button" class="cta w-presetapply" data-preset-apply>' + icon('layout-template') + 'Apply this design</button>')
                /* The ONLY way a shipped design changes: you take a copy and edit
                 * that. */
                + '<button type="button" class="' + (sel.isActive ? 'cta' : 'tgl') + '" data-preset-copy>'
                + icon('copy') + 'Make a copy to customize</button>'
                + (sel.isActive ? '' : '<button type="button" class="tgl" data-preset-cancel>Cancel</button>')
                + '</div>';
        } else {
            status = sel.isActive
                ? 'Your dashboard shows <b>' + escapeHtml(sel.label) + '</b>. Edits below go straight into it.'
                : 'Previewing <b>' + escapeHtml(sel.label) + '</b>. Your dashboard still shows <b>' + escapeHtml(sel.activeLabel) + '</b>.';
            acts = '<div class="w-baracts">'
                + '<form class="w-presetrename" data-preset-rename>'
                + '<input class="w-presetnameinput" type="text" name="name" required maxlength="' + NAME_MAX + '"'
                + ' value="' + escapeHtml(sel.label) + '" aria-label="Rename this preset">'
                + '<button type="submit" class="tgl w-presetrenamebtn">Rename</button>'
                + '</form>'
                + (sel.isActive ? ''
                    : '<button type="button" class="cta w-presetapply" data-preset-apply>' + icon('layout-template') + 'Apply</button>')
                /* Destructive, so it ASKS — through the host's reusable
                 * confirm-modal controller, never the browser's own dialog. The
                 * button carries the question exactly as the server-rendered one
                 * does, and Stimulus connects the controller as it is inserted. */
                + '<button type="button" class="tgl w-presetdel" data-preset-delete'
                + ' data-controller="confirm-modal" data-action="click->confirm-modal#ask"'
                + ' data-confirm-modal-title-value="Delete “' + escapeHtml(sel.label) + '”?"'
                + ' data-confirm-modal-message-value="' + (sel.isActive
                    ? 'This saved layout is thrown away, and your dashboard goes back to the design this surface ships with. This cannot be undone.'
                    : 'This saved layout is thrown away. Your dashboard is untouched. This cannot be undone.')
                + '" data-confirm-modal-confirm-label-value="Delete preset"'
                + ' data-confirm-modal-danger-value="true">' + icon('trash-2') + 'Delete</button>'
                + (sel.isActive ? '' : '<button type="button" class="tgl" data-preset-cancel>Cancel</button>')
                + '</div>';
        }

        return '<div class="w-previewbar' + (sel.isActive ? ' w-previewbar-active' : '') + '" role="status">'
            + '<span class="w-barstatus">' + icon(sel.isActive ? 'check' : 'eye') + '<span>' + status + '</span></span>'
            + acts + '</div>';
    }

    /**
     * ONE WIDGET ON THE CANVAS. The body is CLONED from the <template> the page
     * rendered: the picture of a widget is the widget, rendered by its own Twig
     * partial, so it can never fall out of step with what actually gets added.
     */
    function canvasCard(entry, editable) {
        const w = widgetOf(entry.id);
        const group = groupOf(w.group);
        const card = document.createElement('div');
        card.className = 'w-card' + (editable ? '' : ' w-card-read');
        /* draggable="false" until the grip is pressed: a card that is always
         * draggable makes its own text unselectable. */
        card.setAttribute('draggable', 'false');
        card.setAttribute(ATTR.widget, entry.id);
        card.setAttribute(ATTR.on, '1');
        card.setAttribute(ATTR.cols, String(entry.cols));

        let chrome = '';
        if (editable) {
            const chips = w.spans.map((span) => {
                const chosen = entry.cols === span;

                return '<button type="button" class="mchip w-widthchip' + (chosen ? ' on' : '') + '" '
                    + ATTR.span + '="' + span + '" ' + ATTR.chosen + '="' + (chosen ? 'on' : 'off') + '">'
                    + SPAN_LABELS[span] + '</button>';
            }).join('');
            chrome = '<span class="w-chips"><span class="w-chiplabel">width</span>' + chips + '</span>'
                + '<button type="button" class="tgl w-tgl w-remove" ' + ATTR.toggle + ' title="Remove from this layout">'
                + icon('x') + '<span ' + ATTR.toggleLabel + '>Remove</span></button>';
        }

        card.innerHTML = '<div class="w-head">'
            + (editable
                ? '<b class="w-grip" ' + ATTR.grip + ' title="Drag to set the widget’s place on the dashboard">' + icon('grip-vertical') + '</b>'
                : '')
            + '<span class="w-name">' + escapeHtml(w.label) + '</span>'
            + (group ? '<span class="chip idle w-cardgroup">' + escapeHtml(group.label) + '</span>' : '')
            + chrome
            + '</div>'
            /* The CARD carries the width, so this wrapper is full-bleed inside it
             * and never shrinks the widget a second time. */
            + '<div class="w-preview" ' + ATTR.preview + '></div>';

        const body = card.querySelector('[' + ATTR.preview + ']');
        const source = root.querySelector('[' + ATTR.template + '="' + entry.id + '"]');
        if (source && body) {
            body.appendChild(source.content.cloneNode(true));
        }

        return card;
    }

    function render() {
        const sel = selection();

        root.querySelector('[data-presets="designs"] .w-presetrow').innerHTML = designStrip(sel);
        root.querySelector('[data-presets="mine"] .w-presetrow').innerHTML = mineStrip(sel);

        const bar = root.querySelector('.w-previewbar');
        bar.outerHTML = toolbar(sel);

        const body = root.querySelector('.w-body');
        body.classList.toggle('w-body-preview', !sel.isActive);
        body.querySelector('.w-sectionhead').textContent = 'new' === sel.kind ? 'New preset' : sel.label;
        body.querySelector('.w-sectionsub').textContent = sel.editable
            ? 'Every widget here is the real thing at full size, in the order and at the width the dashboard'
              + ' will use. Drag by the grip to reorder, use the width chips to resize, × to take one off.'
            : 'Every widget here is the real thing at full size — exactly the dashboard this preset produces.';

        const canvas = body.querySelector('.w-canvas');
        canvas.innerHTML = '';
        sel.entries.forEach((entry) => {
            if (widgetOf(entry.id)) {
                canvas.appendChild(canvasCard(entry, sel.editable));
            }
        });
        if (!canvas.children.length) {
            canvas.innerHTML = '<p class="w-canvasempty">Nothing on this canvas yet — add a widget to start composing.</p>';
        }

        const addTile = body.querySelector('[data-picker-open]');
        if (sel.editable && !addTile) {
            const tile = document.createElement('button');
            tile.type = 'button';
            tile.className = 'w-addtile w-addwidget';
            tile.setAttribute('data-picker-open', '');
            tile.innerHTML = icon('plus') + '<span>Add widget</span>';
            body.appendChild(tile);
        } else if (!sel.editable && addTile) {
            addTile.parentNode.removeChild(addTile);
        }

        armGrips();
        paintPicker();
    }

    /* Only the strips — redrawing the whole library mid-edit would throw away
     * focus and scroll position. */
    function repaintStrips() {
        const sel = selection();
        root.querySelector('[data-presets="designs"] .w-presetrow').innerHTML = designStrip(sel);
        root.querySelector('[data-presets="mine"] .w-presetrow').innerHTML = mineStrip(sel);
    }

    /* ---- the canvas as a layout ------------------------------------------ */

    function cards() {
        return Array.prototype.slice.call(root.querySelectorAll('.w-canvas [' + ATTR.widget + ']'));
    }

    /* The DOM is the state: every change re-reads the WHOLE canvas, so a dropped
     * write can never leave a half-applied one. */
    function layoutOfCanvas() {
        const layout = {};
        cards().forEach((card) => {
            layout[card.getAttribute(ATTR.widget)] = parseInt(card.getAttribute(ATTR.cols), 10);
        });

        return layout;
    }

    /* The save endpoint's payload: order plus on/cols for everything on the
     * canvas. A widget the layout does not name is simply absent — the server
     * fills in the rest of the catalogue as off. */
    function payloadOfCanvas() {
        const layout = layoutOfCanvas();
        const order = Object.keys(layout);
        const widgets = {};
        order.forEach((id) => {
            widgets[id] = { on: true, cols: layout[id] };
        });

        return { order: order, widgets: widgets };
    }

    /**
     * WHERE AN EDIT GOES. Composing a new preset keeps it in hand until it is
     * named; editing the active custom preset WRITES THROUGH to it, optimistically
     * — the page already shows the change, and a refusal says so in the notice.
     * A built-in is never editable, so this is never reached on one.
     */
    function edited() {
        if (preview) {
            preview.layout = layoutOfCanvas();
            repaintStrips();
            paintPicker();

            return;
        }
        repaintStrips();
        paintPicker();
        hideNotice();

        const attempted = payloadOfCanvas();
        fetch(root.getAttribute(ATTR.saveUrl), {
            method: 'POST',
            credentials: 'same-origin',
            headers: postHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify(attempted),
        }).then((response) => {
            if (!response.ok) {
                showNotice(failure(response));

                return;
            }
            /* The preset IS the layout, so the card's own count must follow the
             * write that just landed. Its line is the sentence
             * WidgetService::countLine() writes, so the card reads the same
             * whether the server drew it or this did. */
            const mine = presetFor('mine', def.active.id);
            if (mine) {
                mine.layout = layoutOfCanvas();
                const count = Object.keys(mine.layout).length;
                mine.description = count + ' widget' + (1 === count ? '' : 's') + ', in your order and at your widths.';
                repaintStrips();
            }
        }).catch(() => {
            showNotice('That change could not be saved — you appear to be offline. Your dashboard is unchanged.');
        });
    }

    /* ---- preview --------------------------------------------------------- */

    function openPreview(kind, id) {
        if ('new' === kind) {
            preview = { kind: 'new', id: '', label: 'New preset', layout: {} };
        } else {
            const preset = presetFor(kind, id);
            if (!preset) {
                return;
            }
            preview = { kind: kind, id: id, label: preset.label, layout: preset.layout };
        }
        render();
    }

    function closePreview() {
        preview = null;
        render();
    }

    /* ---- the add-widget picker ------------------------------------------- *
     * The modal is rendered in the page and MOVED to <body>: .page is
     * `position:relative; z-index:1`, which is a stacking context — a dialog
     * rendered inside it can never rise above the sidebar or the top bar however
     * high its own z-index goes. The host's shared confirm modal is rendered at
     * the end of <body> for the same reason.
     * ---------------------------------------------------------------------- */

    const picker = root.querySelector('[data-picker]');
    if (picker) {
        document.body.appendChild(picker);
    }
    let pickerOpener = null;
    let pickGroup = '';

    function openPicker(opener) {
        if (!picker) {
            return;
        }
        pickerOpener = opener || null;
        picker.removeAttribute('hidden');
        const search = picker.querySelector('[data-pick-search]');
        if (search) {
            search.value = '';
            pickGroup = '';
            filterPicker('');
            search.focus();
        }
        scaleStages();
    }

    function closePicker() {
        if (picker) {
            picker.setAttribute('hidden', 'hidden');
        }
        if (pickerOpener) {
            pickerOpener.focus();
            pickerOpener = null;
        }
    }

    /* Two filters over one list: the rail picks a group, the search cuts across
     * every group. A search always wins — typing switches the rail back to "All
     * widgets", because a hit you cannot see is a bug. */
    function filterPicker(term) {
        if (!picker) {
            return;
        }
        const needle = term.trim().toLowerCase();
        if (needle) {
            pickGroup = '';
        }
        picker.querySelectorAll('[data-pick-tab]').forEach((tab) => {
            tab.classList.toggle('on', tab.getAttribute('data-pick-tab') === pickGroup);
        });

        let any = false;
        picker.querySelectorAll('[data-pick-group]').forEach((group) => {
            const inGroup = !pickGroup || group.getAttribute('data-pick-group') === pickGroup;
            let shown = 0;
            group.querySelectorAll('.w-pickrow').forEach((row) => {
                const hit = inGroup && (!needle || row.getAttribute('data-pick-name').indexOf(needle) >= 0);
                row.hidden = !hit;
                shown += hit ? 1 : 0;
            });
            /* A group with nothing left in it says nothing at all. */
            group.hidden = 0 === shown;
            any = any || shown > 0;
        });
        picker.querySelector('[data-pick-empty]').hidden = any;
        scaleStages();
    }

    /* An entry already on the canvas says so instead of offering Add. Repainted
     * rather than re-rendered: the stages are real widget markup and rebuilding
     * them on every add would be an animation nobody asked for. */
    function paintPicker() {
        if (!picker) {
            return;
        }
        const layout = layoutOfCanvas();
        picker.querySelectorAll('.w-pickrow').forEach((row) => {
            const control = row.querySelector('.w-pickadd, .w-pickadded');
            if (!control) {
                return;
            }
            const id = control.getAttribute('data-pick-add') || control.getAttribute('data-pick-for');
            if (!id) {
                return;
            }
            control.outerHTML = Object.prototype.hasOwnProperty.call(layout, id)
                ? '<span class="w-pickadded" data-pick-for="' + escapeHtml(id) + '">' + icon('check') + 'In this layout</span>'
                : '<button type="button" class="cta w-pickadd" data-pick-add="' + escapeHtml(id) + '">' + icon('plus') + 'Add</button>';
        });
    }

    /* Fit each render to its stage. MEASURED rather than hard-coded: a stage is
     * full-width or half-width depending on the widget's own catalogue span, and
     * the modal is responsive, so one CSS scale could only ever be right for one
     * of them. A hidden stage measures 0 — skip it; the filter calls this again
     * when it is shown. */
    function scaleStages() {
        if (!picker) {
            return;
        }
        picker.querySelectorAll('.w-pickstage').forEach((stage) => {
            const inner = stage.firstElementChild;
            const room = stage.clientWidth - 20;
            if (room <= 0 || !inner) {
                return;
            }
            inner.style.width = PICK_STAGE_WIDTH + 'px';
            const scale = Math.min(1, room / PICK_STAGE_WIDTH);
            inner.style.transform = 'scale(' + scale + ')';
            /* The stage keeps its uniform height; a render taller than that is
             * clipped and the stage's own fade says so. A shorter one is centred,
             * never stretched. */
            const natural = inner.scrollHeight * scale;
            inner.style.marginTop = natural < stage.clientHeight
                ? Math.round((stage.clientHeight - natural) / 2) + 'px'
                : '0px';
        });
    }

    function addWidget(id) {
        const layout = layoutOfCanvas();
        if (Object.prototype.hasOwnProperty.call(layout, id)) {
            return;
        }
        /* Appended: last on the canvas, at its catalogue default width. */
        const sel = selection();
        sel.entries.push({ id: id, cols: widgetOf(id).cols });
        const canvas = root.querySelector('.w-canvas');
        const empty = canvas.querySelector('.w-canvasempty');
        if (empty) {
            canvas.innerHTML = '';
        }
        canvas.appendChild(canvasCard({ id: id, cols: widgetOf(id).cols }, true));
        armGrips();
        closePicker();
        edited();
    }

    if (picker) {
        picker.addEventListener('input', (event) => {
            if (event.target.closest('[data-pick-search]')) {
                filterPicker(event.target.value);
            }
        });
        picker.addEventListener('click', (event) => {
            if (event.target.closest('[data-picker-close]')) {
                closePicker();

                return;
            }
            const pick = event.target.closest('[data-pick-add]');
            if (pick) {
                addWidget(pick.getAttribute('data-pick-add'));

                return;
            }
            const tab = event.target.closest('[data-pick-tab]');
            if (!tab) {
                return;
            }
            pickGroup = tab.getAttribute('data-pick-tab');
            picker.querySelector('[data-pick-search]').value = '';
            filterPicker('');
            picker.querySelector('.w-pickscroll').scrollTop = 0;
        });
    }

    document.addEventListener('keydown', (event) => {
        if ('Escape' === event.key && picker && !picker.hasAttribute('hidden')) {
            closePicker();
        }
    });

    /* ---- clicks ---------------------------------------------------------- */

    root.addEventListener('click', (event) => {
        const card = event.target.closest ? event.target.closest('.w-canvas [' + ATTR.widget + ']') : null;

        /* × → the widget comes OFF this layout and leaves the canvas. It is not
         * dimmed in place: the canvas only ever shows the composition. */
        if (card && event.target.closest('[' + ATTR.toggle + ']')) {
            card.parentNode.removeChild(card);
            if (!cards().length) {
                root.querySelector('.w-canvas').innerHTML =
                    '<p class="w-canvasempty">Nothing on this canvas yet — add a widget to start composing.</p>';
            }
            edited();

            return;
        }

        /* width chip → the span the cell gets on the dashboard. The canvas
         * re-lays itself, because .w-card reads data-widget-cols too. */
        const chip = event.target.closest('[' + ATTR.span + ']');
        if (card && chip) {
            card.setAttribute(ATTR.cols, chip.getAttribute(ATTR.span));
            paint(card);
            edited();

            return;
        }

        const opener = event.target.closest('[data-picker-open]');
        if (opener) {
            openPicker(opener);

            return;
        }

        const sel = selection();

        /* APPLY — the commit that changes what the dashboard shows. */
        if (event.target.closest('[data-preset-apply]')) {
            commit(
                'design' === sel.kind ? url(ATTR.presetUrl, sel.id) : url(ATTR.presetApplyUrl, sel.id),
                tokenBody({}),
            );

            return;
        }

        /* COPY — the ONLY way a shipped design becomes editable. */
        if (event.target.closest('[data-preset-copy]')) {
            commit(url(ATTR.presetCopyUrl, sel.id), tokenBody({}));

            return;
        }

        if (event.target.closest('[data-preset-cancel]')) {
            closePreview();

            return;
        }

        /* Delete asks first: the click only opens the question, and the
         * confirm-modal:confirmed listener below is what writes. */
        if (event.target.closest('[data-preset-delete]')) {
            return;
        }

        /* Clicking a card SELECTS it — the whole card is the control, so nothing
         * about "which one am I looking at" depends on hitting a small button.
         * The active card is already the selection. */
        const presetEl = event.target.closest('.w-preset');
        if (presetEl && !presetEl.classList.contains('w-preset-on')
            && !presetEl.classList.contains('w-preset-active')) {
            openPreview(presetEl.getAttribute('data-preset-kind'), presetEl.getAttribute('data-preset-id'));
        }
    });

    /* The answer to a Delete question, whoever asked it — the toolbar's own
     * button, or the plain form the page rendered (whose submit the controller
     * lets through on its own). Delegated, because the toolbar is redrawn. */
    root.addEventListener('confirm-modal:confirmed', (event) => {
        if (!event.target.closest('[data-preset-delete]')) {
            return;
        }
        const sel = selection();
        if ('mine' === sel.kind) {
            commit(url(ATTR.presetDeleteUrl, sel.id), tokenBody({}));
        }
    });

    root.addEventListener('keydown', (event) => {
        /* A card is a control, so the keyboard must reach it. */
        if ('Enter' === event.key || ' ' === event.key) {
            const presetEl = event.target.closest ? event.target.closest('.w-preset') : null;
            if (presetEl && presetEl === event.target) {
                event.preventDefault();
                presetEl.click();
            }
        }
    });

    root.addEventListener('submit', (event) => {
        const sel = selection();

        /* SAVE — creates the preset from what was COMPOSED on the canvas and makes
         * it the active one: you composed the dashboard you wanted, so that is
         * the dashboard you get. */
        if (event.target.closest('[data-preset-create]')) {
            event.preventDefault();
            const name = event.target.querySelector('input[name=name]').value.trim();
            if (!name) {
                return;
            }
            const body = payloadOfCanvas();
            body.name = name;
            commit(root.getAttribute(ATTR.presetsUrl), body);

            return;
        }

        if (event.target.closest('[data-preset-rename]') && 'mine' === sel.kind) {
            event.preventDefault();
            const value = event.target.querySelector('input[name=name]').value.trim();
            if (value) {
                commit(url(ATTR.presetRenameUrl, sel.id), tokenBody({ name: value }));
            }
        }
    });

    /* ---- the card's chrome, re-stated from its own data attributes -------- */
    /* Only the width chips vary: everything on the canvas is on, by definition. */
    function paint(card) {
        const cols = card.getAttribute(ATTR.cols);
        card.querySelectorAll('[' + ATTR.span + ']').forEach((chip) => {
            const chosen = chip.getAttribute(ATTR.span) === cols;
            chip.setAttribute(ATTR.chosen, chosen ? 'on' : 'off');
            chip.classList.toggle('on', chosen);
        });
    }

    /* ---- drag to place --------------------------------------------------- *
     * The grip is the handle the design draws, but the whole card is the drag
     * source — a 600px-tall widget you may only grab by a 22px grip is not a
     * handle, it is a target.
     *
     * The card in flight STAYS where it was, dimmed under a dotted outline, and a
     * slot tracks the cursor between the cards. Moving the card itself instead
     * would make the page jump under the pointer and leave nothing on screen
     * saying "this is the one you are moving"; the slot says where it lands.
     * ---------------------------------------------------------------------- */

    let dragging = null;
    let slot = null;
    // Where the card stood before the drag, so a cancelled drag can put it back.
    let origin = null;
    let dropped = false;
    // Held while neighbours are sliding, so a storm of dragover events cannot
    // mutate the DOM out from under an animation that is mid-flight.
    let animating = false;
    let animationTimer = null;

    const reducedMotion = window.matchMedia
        ? window.matchMedia('(prefers-reduced-motion: reduce)')
        : null;

    function prefersReducedMotion() {
        return !!reducedMotion && reducedMotion.matches;
    }

    /* Handle-gated: the card is draggable ONLY while the grip is held. A card
     * that is permanently draggable makes its own text unselectable and its
     * chips awkward to click. Re-armed after every render, because the canvas is
     * rebuilt whenever the selection changes. */
    function armGrips() {
        cards().forEach((card) => {
            const grip = card.querySelector('[' + ATTR.grip + ']');
            if (grip) {
                grip.addEventListener('mousedown', () => card.setAttribute('draggable', 'true'));
            }
        });
    }

    function dropSlot() {
        if (!slot) {
            slot = document.createElement('div');
            slot.className = 'w-dropslot';
            // Decoration: it names no widget and must not be read out.
            slot.setAttribute('aria-hidden', 'true');
        }

        return slot;
    }

    function clearTargets() {
        root.querySelectorAll('.w-droptarget').forEach((card) => card.classList.remove('w-droptarget'));
    }

    /* FLIP: measure every card First, run the mutation, measure Last, Invert the
     * difference as a transform, then Play it out. Without this the cards jump
     * whenever the slot moves and there is no reading of which way they went.
     *
     * The dragged card is excluded — it is the ghost, held still on purpose.
     * Under prefers-reduced-motion the mutation is applied instantly and the drop
     * slot stays the only affordance, which is the point of that setting. */
    function flip(mutate) {
        if (prefersReducedMotion()) {
            mutate();

            return;
        }

        const moved = cards().filter((card) => card !== dragging);
        const before = moved.map((card) => card.getBoundingClientRect());

        mutate();

        const deltas = moved.map((card, index) => {
            const now = card.getBoundingClientRect();

            return { x: before[index].left - now.left, y: before[index].top - now.top };
        });

        let slid = false;
        moved.forEach((card, index) => {
            const delta = deltas[index];
            if (!delta.x && !delta.y) {
                return;
            }
            slid = true;
            card.classList.remove('w-sliding');
            card.style.transform = 'translate(' + delta.x + 'px, ' + delta.y + 'px)';
        });
        if (!slid) {
            return;
        }

        // Force the inverted position to be painted before the transition is
        // armed, or the browser collapses both steps into no movement at all.
        void root.offsetWidth;

        animating = true;
        window.requestAnimationFrame(() => {
            moved.forEach((card) => {
                card.classList.add('w-sliding');
                card.style.transform = '';
            });

            window.clearTimeout(animationTimer);
            animationTimer = window.setTimeout(() => {
                moved.forEach((card) => card.classList.remove('w-sliding'));
                animating = false;
            }, SLIDE_MS + 40);
        });
    }

    /* Put the card back where the drag found it — the slot never became a drop. */
    function revertDrag() {
        flip(() => {
            if (slot && slot.parentNode) {
                slot.parentNode.removeChild(slot);
            }
            if (origin) {
                origin.parent.insertBefore(dragging, origin.next);
            }
        });
    }

    /* Land the card where the slot stands. */
    function placeDrag() {
        flip(() => {
            if (slot && slot.parentNode) {
                slot.parentNode.insertBefore(dragging, slot);
                slot.parentNode.removeChild(slot);
            }
        });
    }

    /* One exit for every drag, dropped or cancelled. A cancelled drag (Esc, or a
     * release outside the library) fires dragend WITHOUT drop: it restores the
     * pre-drag order and posts nothing — the layout on the server never changed,
     * so writing it back would be a lie. */
    function endDrag() {
        if (!dragging) {
            return;
        }
        if (dropped) {
            placeDrag();
        } else {
            revertDrag();
        }

        dragging.classList.remove('w-dragging');
        dragging.setAttribute('draggable', 'false');
        clearTargets();
        dragging = null;
        origin = null;

        if (dropped) {
            dropped = false;
            edited();
        }
    }

    root.addEventListener('dragstart', (event) => {
        const card = event.target.closest ? event.target.closest('.w-canvas [' + ATTR.widget + ']') : null;
        if (!card) {
            return;
        }
        dragging = card;
        dropped = false;
        // Recorded BEFORE the slot is inserted, so it is the card's own place.
        origin = { parent: card.parentNode, next: card.nextSibling };
        card.classList.add('w-dragging');
        // The slot shows the FOOTPRINT this widget will take, not a generic bar.
        dropSlot().setAttribute(ATTR.cols, card.getAttribute(ATTR.cols));
        // It opens where the card already is, so a drag that goes nowhere puts it
        // back exactly where it was.
        card.parentNode.insertBefore(dropSlot(), card.nextSibling);
        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
            // Firefox starts no drag at all without some payload.
            event.dataTransfer.setData('text/plain', card.getAttribute(ATTR.widget));
        }
    });

    root.addEventListener('dragover', (event) => {
        if (!dragging) {
            return;
        }
        // Always accept the drop, even mid-slide — refusing here would cancel the
        // drag outright.
        event.preventDefault();
        if (event.dataTransfer) {
            event.dataTransfer.dropEffect = 'move';
        }
        if (animating) {
            return;
        }

        const over = event.target.closest ? event.target.closest('.w-canvas [' + ATTR.widget + ']') : null;
        if (!over || over === dragging || over.parentNode !== dragging.parentNode) {
            return;
        }

        // The card under the cursor lights up, and the slot opens before or after
        // it, so the landing place is stated twice: which neighbour, and which
        // side of it.
        //
        // Which axis decides "after" depends on how the card sits. Cards share a
        // row once they are narrower than the grid, and there the meaningful
        // question is left-or-right of its middle; a full-width card is only ever
        // above or below. Semantics stay insert-before/insert-after either way —
        // this is an insertion model, never a swap.
        clearTargets();
        over.classList.add('w-droptarget');
        const box = over.getBoundingClientRect();
        const sharesRow = box.width < over.parentNode.clientWidth * 0.9;
        const after = sharesRow
            ? event.clientX > box.left + box.width / 2
            : event.clientY > box.top + box.height / 2;
        const reference = after ? over.nextSibling : over;
        if (reference === slot) {
            return;
        }

        flip(() => over.parentNode.insertBefore(dropSlot(), reference));
    });

    root.addEventListener('drop', (event) => {
        if (!dragging) {
            return;
        }
        event.preventDefault();
        dropped = true;
        endDrag();
    });

    root.addEventListener('dragend', endDrag);

    /* A grip pressed but never dragged still armed the attribute; disarm it so
     * the card does not stay draggable for the rest of the session. No mouseup
     * fires during a real drag, so this never races dragend. */
    document.addEventListener('mouseup', () => {
        if (dragging) {
            return;
        }
        cards().forEach((card) => card.setAttribute('draggable', 'false'));
    });

    /* ---- reset ------------------------------------------------------------ */
    /* Reset throws the choice away, so it asks first — through the host's
     * reusable confirm-modal controller, never the browser's own dialog. The
     * button carries its data attributes and dispatches `confirm-modal:confirmed`
     * when the person agrees; where that controller is absent the click still
     * resets, because the library may not require a controller to be installed. */
    const reset = document.querySelector('[' + ATTR.reset + ']');
    if (reset) {
        reset.addEventListener('confirm-modal:confirmed', () => {
            commit(root.getAttribute(ATTR.resetUrl), tokenBody({}));
        });

        if (!reset.hasAttribute('data-controller')) {
            reset.addEventListener('click', () => {
                reset.dispatchEvent(new CustomEvent('confirm-modal:confirmed'));
            });
        }
    }

    /* ---- small helpers ---------------------------------------------------- */

    /* The handful of icons the FRAMEWORK itself draws, mirroring
     * {{ ux_icon('lucide:<name>') }} one path at a time — a widget's own markup
     * carries its own icons, and these are only for the chrome this file builds
     * after the page was rendered. */
    const ICONS = {
        'plus': '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'grip-vertical': '<circle cx="9" cy="12" r="1"/><circle cx="9" cy="5" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="15" cy="19" r="1"/>',
        'layout-template': '<rect width="18" height="7" x="3" y="3" rx="1"/><rect width="9" height="7" x="3" y="14" rx="1"/><rect width="5" height="7" x="16" y="14" rx="1"/>',
        'bookmark': '<path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16z"/>',
        'trash-2': '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M10 11v6"/><path d="M14 11v6"/>',
        'arrow-right': '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'check': '<path d="M20 6 9 17l-5-5"/>',
        'x': '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'copy': '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
        'eye': '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
    };

    function icon(name) {
        return '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            + 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + ICONS[name] + '</svg>';
    }

    function escapeHtml(value) {
        return String(value === null || undefined === value ? '' : value).replace(/[&<>"']/g, (ch) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[ch]));
    }

    /* Whatever the page rendered IS the server's state: arm the chrome over it
     * rather than redrawing it, so nothing flashes on load. */
    armGrips();
    paintPicker();
    scaleStages();
    window.addEventListener('resize', scaleStages);

    return true;
}

export default initWidgetLibrary;
