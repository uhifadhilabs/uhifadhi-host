import { Controller } from '@hotwired/stimulus';

/*
 * The areas register: wires the search box, the filter pills (All / Live data /
 * Queued) and column sorting — all client-side over the rendered rows. A pill exists
 * only where the host can count the thing it filters on.
 * Each <tr> carries data-* keys; pills carry data-filter; sortable
 * <th> carry data-sort. Default sort: total loss, descending (matches the design).
 */
export default class extends Controller {
    static targets = ['search', 'pill', 'row', 'header', 'sortLabel'];

    connect() {
        this.filter = 'all';
        this.query = '';
        this.sortKey = 'loss';
        this.sortDir = -1; // -1 desc, 1 asc
        this.render();
    }

    search() {
        this.query = this.searchTarget.value.trim().toLowerCase();
        this.render();
    }

    setFilter(event) {
        event.preventDefault();
        this.filter = event.currentTarget.dataset.filter;
        this.render();
    }

    sortBy(event) {
        event.preventDefault();
        const key = event.currentTarget.dataset.sort;
        if (key === this.sortKey) {
            this.sortDir = -this.sortDir;
        } else {
            this.sortKey = key;
            this.sortDir = key === 'name' ? 1 : -1; // names A→Z, numbers high→low
        }
        this.render();
    }

    render() {
        // 1. filter + search → visibility
        this.rowTargets.forEach((row) => {
            const matchesQuery = !this.query || (row.dataset.name || '').includes(this.query);
            const matchesFilter = this.rowMatchesFilter(row);
            row.hidden = !(matchesQuery && matchesFilter);
        });

        // 2. sort the visible rows and reorder them in the DOM
        const tbody = this.rowTargets[0]?.parentElement;
        if (tbody) {
            const num = (r) => Number(r.dataset[this.sortKey] || 0);
            [...this.rowTargets]
                .sort((a, b) => {
                    if (this.sortKey === 'name') {
                        return this.sortDir * (a.dataset.name || '').localeCompare(b.dataset.name || '');
                    }
                    return this.sortDir * (num(a) - num(b));
                })
                .forEach((row) => tbody.appendChild(row));
        }

        // 3. reflect active pill, sort label, and header arrows
        this.pillTargets.forEach((p) => p.classList.toggle('on', p.dataset.filter === this.filter));
        if (this.hasSortLabelTarget) {
            const label = this.headerTargets.find((h) => h.dataset.sort === this.sortKey)?.dataset.label || this.sortKey;
            this.sortLabelTarget.textContent = `sort: ${label} ${this.sortDir < 0 ? '↓' : '↑'}`;
        }
        this.headerTargets.forEach((h) => {
            const active = h.dataset.sort === this.sortKey;
            h.dataset.active = active ? (this.sortDir < 0 ? 'desc' : 'asc') : '';
        });
    }

    // Whole-row click opens the area (ignoring clicks on inner links/buttons).
    open(event) {
        if (event.target.closest('a')) {
            return;
        }
        const href = event.currentTarget.dataset.href;
        if (href) {
            window.location = href;
        }
    }

    rowMatchesFilter(row) {
        switch (this.filter) {
            case 'live': return row.dataset.live === '1';
            case 'queued': return row.dataset.live === '0';
            default: return true; // all
        }
    }
}
