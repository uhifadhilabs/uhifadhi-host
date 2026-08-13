import { Controller } from '@hotwired/stimulus';

/*
 * Drag-to-reorder, working for both a single-column list (customize modules) and a two-column grid
 * (a module's visualizations). Grabbing a row's grip makes it draggable; while dragging, the row is
 * placed before the nearest sibling that comes *after* the cursor in reading order (so a card in the
 * right column can move into a left slot); on drop the new order (each row's uuid) is POSTed.
 * A row without a grip (the pinned Overview) never moves.
 */
export default class extends Controller {
    static targets = ['row', 'handle'];
    static values = { url: String, token: String };

    connect() {
        this.dragging = null;

        this.rowTargets.forEach((row) => {
            const handle = row.querySelector('[data-module-order-target="handle"]');
            if (!handle) return; // pinned row — not draggable

            row.setAttribute('draggable', 'false');
            handle.addEventListener('mousedown', () => row.setAttribute('draggable', 'true'));

            row.addEventListener('dragstart', (e) => {
                this.dragging = row;
                row.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
            });
            row.addEventListener('dragend', () => {
                row.classList.remove('dragging');
                row.setAttribute('draggable', 'false');
                this.dragging = null;
                this.persist();
            });
        });

        // One dragover on the container handles placement across columns.
        this.element.addEventListener('dragover', (e) => {
            if (!this.dragging) return;
            e.preventDefault();
            const after = this.elementAfter(e.clientX, e.clientY);
            if (after == null) {
                this.element.appendChild(this.dragging);
            } else if (after !== this.dragging) {
                this.element.insertBefore(this.dragging, after);
            }
        });
    }

    /* The nearest draggable row whose centre is after (x, y) in reading order, or null to append. */
    elementAfter(x, y) {
        let best = null;
        let bestDist = Infinity;

        this.rowTargets.forEach((row) => {
            if (row === this.dragging || !row.querySelector('[data-module-order-target="handle"]')) return;
            const box = row.getBoundingClientRect();
            const cx = box.left + box.width / 2;
            const cy = box.top + box.height / 2;
            // "after the cursor": a later row, or same row but to the right.
            const isAfter = cy > y + 4 || (Math.abs(cy - y) <= box.height / 2 && cx > x);
            if (!isAfter) return;
            const dist = Math.hypot(cx - x, cy - y);
            if (dist < bestDist) {
                bestDist = dist;
                best = row;
            }
        });

        return best;
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
