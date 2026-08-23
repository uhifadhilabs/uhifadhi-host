import { Controller } from '@hotwired/stimulus';

/*
 * The R-style dataframe viewer, wired over server-rendered rows (never re-fetching): global search,
 * per-column filters (numeric columns understand `a-b` ranges and >, >=, <, <= comparators; text
 * columns do a substring match), click-to-sort with a three-state caret (↕ → ▲ → ▼ → ↕), and
 * client-side pagination. The DOM rows are the source of truth; the controller only reorders and
 * shows/hides them, so cell formatting stays owned by Twig.
 */
export default class extends Controller {
    static targets = ['body', 'search', 'filter', 'caret', 'page', 'pages', 'range', 'total', 'matchCount'];
    static values = { pageSize: { type: Number, default: 25 } };

    connect() {
        this.rows = Array.from(this.bodyTarget.rows);
        this.rows.forEach((row) => { row.dataset.order = ''; }); // preserved original order = DOM order
        this.sortCol = null;
        this.sortDir = null; // null | 'asc' | 'desc'
        this.pageIndex = 0;
        this.apply();
    }

    // ── input handlers ────────────────────────────────────────────────
    search() { this.pageIndex = 0; this.apply(); }
    filter() { this.pageIndex = 0; this.apply(); }

    sort(event) {
        const col = Number(event.currentTarget.dataset.col);
        if (this.sortCol === col) {
            this.sortDir = this.sortDir === 'asc' ? 'desc' : this.sortDir === 'desc' ? null : 'asc';
            if (this.sortDir === null) this.sortCol = null;
        } else {
            this.sortCol = col;
            this.sortDir = 'asc';
        }
        this.pageIndex = 0;
        this.apply();
    }

    prev() { if (this.pageIndex > 0) { this.pageIndex -= 1; this.render(); } }
    next() { if ((this.pageIndex + 1) * this.pageSizeValue < this.matched.length) { this.pageIndex += 1; this.render(); } }
    first() { if (this.pageIndex !== 0) { this.pageIndex = 0; this.render(); } }
    last() { const l = this.pageCount() - 1; if (this.pageIndex !== l) { this.pageIndex = l; this.render(); } }
    goto(event) { this.pageIndex = Number(event.currentTarget.dataset.page); this.render(); }

    pageCount() { return Math.max(1, Math.ceil(this.matched.length / this.pageSizeValue)); }


    // ── pipeline: filter → sort → paginate ────────────────────────────
    apply() {
        const q = (this.hasSearchTarget ? this.searchTarget.value : '').trim().toLowerCase();
        const filters = this.filterTargets
            .map((input) => ({ col: Number(input.dataset.col), raw: input.value.trim() }))
            .filter((f) => f.raw !== '');

        this.matched = this.rows.filter((row) => {
            if (q && !row.textContent.toLowerCase().includes(q)) return false;
            return filters.every((f) => this.matchesFilter(row, f));
        });

        if (this.sortCol !== null) {
            const dir = this.sortDir === 'desc' ? -1 : 1;
            const th = this.element.querySelector(`th[data-col="${this.sortCol}"]`);
            const numeric = th && th.dataset.type !== 'chr';
            this.matched.sort((a, b) => dir * this.compare(this.cell(a, this.sortCol), this.cell(b, this.sortCol), numeric));
        }

        this.renderCarets();
        this.render();
    }

    render() {
        const start = this.pageIndex * this.pageSizeValue;
        const pageRows = this.matched.slice(start, start + this.pageSizeValue);

        // Hide everything, then re-append the current page in matched order.
        this.rows.forEach((row) => { row.hidden = true; });
        pageRows.forEach((row) => { row.hidden = false; this.bodyTarget.appendChild(row); });

        const n = this.matched.length;
        const from = n === 0 ? 0 : start + 1;
        const to = Math.min(start + this.pageSizeValue, n);
        if (this.hasRangeTarget) this.rangeTarget.textContent = `${from}–${to}`;
        if (this.hasTotalTarget) this.totalTarget.textContent = String(n);
        if (this.hasMatchCountTarget) this.matchCountTarget.textContent = String(n);
        if (this.hasPageTarget) this.pageTarget.textContent = String(this.pageIndex + 1);
        this.renderPager();
    }

    // Numbered page window (1 … 4 [5] 6 … 40) — appears only when there are pages to jump.
    renderPager() {
        if (!this.hasPagesTarget) return;
        const count = this.pageCount();
        const current = this.pageIndex;
        this.pagesTarget.innerHTML = '';
        if (count <= 1) return;

        const wanted = new Set([0, count - 1]);
        for (let i = current - 2; i <= current + 2; i += 1) {
            if (i >= 0 && i < count) wanted.add(i);
        }
        let previous = -1;
        [...wanted].sort((a, b) => a - b).forEach((i) => {
            if (previous !== -1 && i - previous > 1) {
                const gap = document.createElement('span');
                gap.className = 'gap';
                gap.textContent = '…';
                this.pagesTarget.appendChild(gap);
            }
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = String(i + 1);
            btn.dataset.page = String(i);
            btn.setAttribute('aria-label', `page ${i + 1}`);
            if (i === current) btn.classList.add('cur');
            btn.addEventListener('click', (event) => this.goto(event));
            this.pagesTarget.appendChild(btn);
            previous = i;
        });
    }

    // ── helpers ───────────────────────────────────────────────────────
    cell(row, col) {
        const td = row.querySelector(`td[data-col="${col}"]`);
        return td ? td.textContent.trim() : '';
    }

    compare(a, b, numeric) {
        if (numeric) return (parseFloat(a) || 0) - (parseFloat(b) || 0);
        return a.localeCompare(b);
    }

    matchesFilter(row, { col, raw }) {
        const value = this.cell(row, col);
        const th = this.element.querySelector(`th[data-col="${col}"]`);
        const numeric = th && th.dataset.type !== 'chr';
        if (!numeric) return value.toLowerCase().includes(raw.toLowerCase());

        const num = parseFloat(value);
        const range = raw.match(/^\s*(-?\d+(?:\.\d+)?)\s*-\s*(-?\d+(?:\.\d+)?)\s*$/);
        if (range) return num >= parseFloat(range[1]) && num <= parseFloat(range[2]);
        const cmp = raw.match(/^\s*(>=|<=|>|<)\s*(-?\d+(?:\.\d+)?)\s*$/);
        if (cmp) {
            const t = parseFloat(cmp[2]);
            return cmp[1] === '>' ? num > t : cmp[1] === '>=' ? num >= t : cmp[1] === '<' ? num < t : num <= t;
        }
        return value.toLowerCase().includes(raw.toLowerCase());
    }

    renderCarets() {
        this.caretTargets.forEach((caret) => {
            const col = Number(caret.closest('.rdf-sort').dataset.col);
            if (col === this.sortCol && this.sortDir) {
                caret.textContent = this.sortDir === 'asc' ? '▲' : '▼';
                caret.classList.add('on');
            } else {
                caret.textContent = '↕';
                caret.classList.remove('on');
            }
        });
    }

    exportCsv() {
        const head = Array.from(this.element.querySelectorAll('thead th[data-col] .rdf-sort'))
            .map((b) => b.firstChild.textContent.trim());
        const lines = [head.join(',')];
        this.matched.forEach((row) => {
            const cells = Array.from(row.querySelectorAll('td[data-col]')).map((td) => {
                const v = td.textContent.trim();
                return /[",\n]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v;
            });
            lines.push(cells.join(','));
        });
        const blob = new Blob([lines.join('\n')], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'dataframe.csv';
        a.click();
        URL.revokeObjectURL(url);
    }
}
