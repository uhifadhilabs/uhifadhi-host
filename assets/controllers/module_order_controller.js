import { Controller } from '@hotwired/stimulus';

/*
 * Drag-to-SWAP reordering with a live, animated preview — for both the single-column module list
 * (customize modules) and the two-column visualization grid. Grab a row by its grip and drag it over
 * another: the two exchange places immediately (previewing the result); dragging over a different row
 * moves the preview; dragging back over your origin slot undoes it; dropping keeps it and POSTs the
 * new order; a cancelled drag reverts. Only ever two cards move — nothing else shifts.
 *
 * Swaps are animated with FLIP (measure, swap, invert, play) so the displaced card slides into place,
 * matching the SortableJS feel. The dragged card follows the cursor as the native drag image, so only
 * the other cards are animated. Honours prefers-reduced-motion.
 */
export default class extends Controller {
    static targets = ['row', 'handle'];
    static values = { url: String, token: String };

    connect() {
        this.dragging = null;
        this.previewedWith = null;
        this.dropped = false;
        this.animating = false;
        this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        this.rowTargets.forEach((row) => {
            const handle = row.querySelector('[data-module-order-target="handle"]');
            if (!handle) return; // pinned row — not draggable

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
                // While a swap is animating, don't re-order: the sliding card passes under the cursor
                // and would otherwise re-trigger a swap (flicker), especially for a mid-list drag.
                if (this.animating) return;
                if (!this.dragging || row === this.dragging || this.dragging.classList.contains('pinned')) return;

                // After a pre-swap the other card sits in the dragged card's origin slot; dragging
                // back over it (returning home) undoes the preview so both cards return to origin.
                if (row === this.previewedWith) {
                    this.animate(() => this.swap(this.dragging, this.previewedWith));
                    this.previewedWith = null;
                    return;
                }

                if (row.classList.contains('pinned')) return;
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
        });
    }

    /* Exchange two sibling elements in place. */
    swap(a, b) {
        const parent = a.parentNode;
        const afterA = a.nextSibling === b ? a : a.nextSibling;
        parent.insertBefore(a, b);
        parent.insertBefore(b, afterA);
    }

    /* FLIP: run a DOM mutation, then slide the moved cards from their old positions to their new ones. */
    animate(mutate) {
        if (this.reducedMotion) {
            mutate();
            return;
        }
        const duration = 160;
        const rows = this.rowTargets.filter((r) => r !== this.dragging);
        const first = new Map(rows.map((r) => [r, r.getBoundingClientRect()]));

        mutate();

        // Lock reordering until the slide finishes so the moving card can't re-trigger a swap.
        this.animating = true;
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
            void r.offsetWidth; // force reflow so the invert is applied before the play
            requestAnimationFrame(() => {
                r.style.transition = `transform ${duration}ms ease`;
                r.style.transform = '';
            });
        });
    }

    persist() {
        const body = new URLSearchParams();
        body.append('_token', this.tokenValue);
        this.rowTargets
            .filter((r) => r.querySelector('[data-module-order-target="handle"]'))
            .forEach((r) => body.append('order[]', r.dataset.uuid));

        fetch(this.urlValue, { method: 'POST', body, headers: { 'X-Requested-With': 'fetch' } });
    }
}
