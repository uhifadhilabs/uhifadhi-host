import { Controller } from '@hotwired/stimulus';

/*
 * Drag-to-reorder the active modules on the customize-modules page. Grabbing a row's grip
 * makes it draggable; dragging reorders the DOM live; on drop the new order (each row's
 * AreaModule uuid) is POSTed to persist it. The pinned Overview row stays put.
 */
export default class extends Controller {
    static targets = ['row', 'handle'];
    static values = { url: String, token: String };

    connect() {
        this.rowTargets.forEach((row) => {
            const handle = row.querySelector('[data-module-order-target="handle"]');
            if (!handle) return; // the pinned Overview row — not draggable

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
                this.persist();
            });
            row.addEventListener('dragover', (e) => {
                e.preventDefault();
                if (!this.dragging || this.dragging === row || row.classList.contains('pinned')) return;
                const rect = row.getBoundingClientRect();
                const after = e.clientY - rect.top > rect.height / 2;
                row.parentNode.insertBefore(this.dragging, after ? row.nextSibling : row);
            });
        });
    }

    persist() {
        const body = new URLSearchParams();
        body.append('_token', this.tokenValue);
        this.rowTargets
            .filter((r) => !r.classList.contains('pinned'))
            .forEach((r) => body.append('order[]', r.dataset.uuid));

        fetch(this.urlValue, { method: 'POST', body, headers: { 'X-Requested-With': 'fetch' } });
    }
}
