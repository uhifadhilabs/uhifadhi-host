import { Controller } from '@hotwired/stimulus';

/*
 * The DATA-AWARE configure-visualization form + its live preview.
 *
 * Rules (derived from the bound dataset's column types, carried as data-type on each option):
 *   · Y must be numeric (int/dbl) — chr options are disabled there.
 *   · X: bar/waterfall accept any column; the series types (line/area/step/lowess/scatter)
 *     need an ordered axis, so chr options are disabled for them.
 *   · X and Y exclude each other (plotting a column against itself is never meaningful).
 * If the selected option becomes invalid after a change, the first valid option is chosen.
 *
 * Every change fetches the LIVE PREVIEW from the server — the same SVG engine that renders the
 * real charts — debounced; a 204 means "not drawable yet" and shows the note instead.
 */
export default class extends Controller {
    static targets = ['type', 'x', 'y', 'preview', 'note', 'datasetKey'];
    static values = { previewUrl: String };

    /** Chart types whose x-axis must be ordered (numeric) rather than categorical. */
    static SERIES_TYPES = ['line', 'area', 'step', 'lowess', 'scatter'];

    connect() {
        this.applyRules();
        this.refresh();
    }

    changed() {
        this.applyRules();
        this.refresh();
    }

    applyRules() {
        const type = this.typeTarget.value;
        const seriesX = this.constructor.SERIES_TYPES.includes(type);
        const histogram = type === 'histogram';

        // A histogram bins one numeric column — the x axis plays no part.
        this.xTarget.disabled = histogram;
        this.xTarget.closest('.viz-field')?.classList.toggle('viz-field-off', histogram);

        if (!histogram) {
            this.constrain(this.xTarget, (opt) => {
                if (opt.value === this.yTarget.value) return false;         // x ≠ y
                if (seriesX && opt.dataset.type === 'chr') return false;    // ordered x for series types
                return true;
            });
        }
        this.constrain(this.yTarget, (opt) => {
            if (!histogram && opt.value === this.xTarget.value) return false; // y ≠ x
            return opt.dataset.type !== 'chr';                                // y is always numeric
        });
    }

    /** Disable invalid options; if the current selection became invalid, pick the first valid one. */
    constrain(select, isValid) {
        let currentInvalid = false;
        for (const opt of select.options) {
            const ok = isValid(opt);
            opt.disabled = !ok;
            if (!ok && opt.selected) currentInvalid = true;
        }
        if (currentInvalid) {
            const first = [...select.options].find((opt) => !opt.disabled);
            if (first) select.value = first.value;
        }
    }

    refresh() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.fetchPreview(), 250);
    }

    async fetchPreview() {
        const params = new URLSearchParams({
            type: this.typeTarget.value,
            xAxis: this.xTarget.value,
            yAxis: this.yTarget.value,
            datasetKey: this.hasDatasetKeyTarget ? this.datasetKeyTarget.value : '',
        });
        try {
            const res = await fetch(`${this.previewUrlValue}?${params}`);
            if (res.status === 204) {
                this.previewTarget.innerHTML = '<div class="viz-scaffold"><span class="chip warn">no preview</span></div>';
                this.noteTarget.textContent = 'This configuration has nothing to draw yet — the dataset may be missing, or the columns don\'t fit.';
                return;
            }
            if (!res.ok) throw new Error(String(res.status));
            this.previewTarget.innerHTML = await res.text();
            this.noteTarget.textContent = '';
        } catch (e) {
            this.noteTarget.textContent = 'Preview unavailable.';
        }
    }

    disconnect() {
        clearTimeout(this.timer);
    }
}
