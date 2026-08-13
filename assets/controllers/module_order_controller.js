import { Controller } from '@hotwired/stimulus';

/*
 * Drag-to-SWAP reordering with a live, animated preview. Works on one list (the visualization grid)
 * or several SYNCED lists that hold the same items keyed by data-uuid (the customize page's module
 * pills + the detailed Active-modules rows): a swap in one list is mirrored into the others by uuid,
 * so reordering either representation reorders both, live.
 *
 * Grab a row by its grip and drag it over another in the same list: the two exchange places
 * (previewing the result); dragging over a different row moves the preview; dragging back to your
 * origin slot undoes it; dropping keeps it and POSTs the new order; a cancelled drag reverts. Only
 * ever two items move. Swaps are FLIP-animated (no library). Honours prefers-reduced-motion.
 */
export default class extends Controller {
    static targets = ['list', 'row', 'handle'];
    static values = { url: String, token: String };

    connect() {
        this.dragging = null;
        this.previewedWith = null;
        this.dropped = false;
        this.animating = false;
        this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        this.rowTargets.forEach((row) => this.wire(row));
    }

    lists() {
        return this.hasListTarget ? this.listTargets : [this.element];
    }

    listOf(row) {
        return this.hasListTarget ? row.closest('[data-module-order-target="list"]') : this.element;
    }

    rowsIn(list) {
        return Array.from(list.querySelectorAll('[data-module-order-target="row"]'));
    }

    wire(row) {
        const handle = row.querySelector('[data-module-order-target="handle"]');
        if (!handle) return; // pinned / non-draggable row

        row.setAttribute('draggable', 'false');
        handle.addEventListener('mousedown', () => row.setAttribute('draggable', 'true'));

        row.addEventListener('dragstart', (e) => {
            this.dragging = row;
            this.previewedWith = null;
            this.dropped = false;
            row.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        });

        row.addEventListener('dragover', (e) => {
            e.preventDefault();
            if (this.animating || !this.dragging || row === this.dragging) return;
            if (this.dragging.classList.contains('pinned') || row.classList.contains('pinned')) return;
            if (this.listOf(row) !== this.listOf(this.dragging)) return; // reorder within the grabbed list

            // Dragging back over the card now sitting in the origin slot undoes the preview.
            if (row === this.previewedWith) {
                this.animate(() => this.swap(this.dragging, this.previewedWith));
                this.previewedWith = null;
                return;
            }

            this.animate(() => {
                if (this.previewedWith) this.swap(this.dragging, this.previewedWith); // undo previous preview
                this.swap(this.dragging, row); // preview this one
            });
            this.previewedWith = row;
        });

        row.addEventListener('drop', (e) => {
            e.preventDefault();
            if (this.previewedWith) {
                this.dropped = true;
                this.persist();
            }
        });

        row.addEventListener('dragend', () => {
            row.classList.remove('dragging');
            row.setAttribute('draggable', 'false');
            if (!this.dropped && this.previewedWith) this.animate(() => this.swap(this.dragging, this.previewedWith));
            this.dragging = null;
            this.previewedWith = null;
            this.dropped = false;
        });
    }

    /* Swap two rows in their list, and mirror the swap (by uuid) into every other synced list. */
    swap(a, b) {
        const list = this.listOf(a);
        this.swapNodes(a, b);

        this.lists().forEach((other) => {
            if (other === list) return;
            const a2 = other.querySelector(`[data-module-order-target="row"][data-uuid="${a.dataset.uuid}"]`);
            const b2 = other.querySelector(`[data-module-order-target="row"][data-uuid="${b.dataset.uuid}"]`);
            if (a2 && b2) this.swapNodes(a2, b2);
        });
    }

    swapNodes(a, b) {
        const parent = a.parentNode;
        const afterA = a.nextSibling === b ? a : a.nextSibling;
        parent.insertBefore(a, b);
        parent.insertBefore(b, afterA);
    }

    /* FLIP: run a DOM mutation, then slide every moved row from its old position to its new one. */
    animate(mutate) {
        if (this.reducedMotion) {
            mutate();
            return;
        }
        const duration = 160;
        const rows = this.rowTargets.filter((r) => r !== this.dragging);
        const first = new Map(rows.map((r) => [r, r.getBoundingClientRect()]));

        mutate();

        this.animating = true; // lock reordering until the slide finishes (prevents flicker)
        clearTimeout(this.animTimer);
        this.animTimer = setTimeout(() => {
            this.animating = false;
        }, duration);

        rows.forEach((r) => {
            const last = r.getBoundingClientRect();
            const before = first.get(r);
            const dx = before.left - last.left;
            const dy = before.top - last.top;
            if (!dx && !dy) return;

            r.style.transition = 'none';
            r.style.transform = `translate(${dx}px, ${dy}px)`;
            void r.offsetWidth; // reflow so the invert applies before the play
            requestAnimationFrame(() => {
                r.style.transition = `transform ${duration}ms ease`;
                r.style.transform = '';
            });
        });
    }

    persist() {
        const body = new URLSearchParams();
        body.append('_token', this.tokenValue);
        this.rowsIn(this.lists()[0])
            .filter((r) => r.querySelector('[data-module-order-target="handle"]'))
            .forEach((r) => body.append('order[]', r.dataset.uuid));

        fetch(this.urlValue, { method: 'POST', body, headers: { 'X-Requested-With': 'fetch' } });
    }
}
