import { Controller } from '@hotwired/stimulus';

/*
 * THE AREA OVERVIEW'S ONE POLLER.
 *
 * Exactly one endpoint refreshes exactly what wears the live dot: the right-now
 * strip, and the map layers whose owning module said they move. An overview with
 * six independent pollers is a load test, so this controller sits on the grid
 * once and nothing else on the page fetches anything.
 *
 * The strip arrives as ITS OWN MARKUP, so a refreshed tile cannot be drawn
 * differently from a rendered one. The layers are handed to the map by a plain
 * DOM event rather than a controller reaching into another controller — the map
 * knows how to draw its own layers and nothing else has to.
 *
 * A failed poll is silent and the interval survives it. A wall display that
 * throws an error banner at 03:00 because the network blinked is worse than a
 * wall display showing a number thirty seconds old.
 */
export default class extends Controller {
    static values = { url: String, interval: { type: Number, default: 30000 } };

    connect() {
        // Never in a Turbo preview: a cached snapshot must not start a timer.
        if (this.element.closest('[data-turbo-preview]')) return;
        this.timer = window.setInterval(() => this.refresh(), this.intervalValue);
        // Pause while the tab is hidden. Nobody is reading it, and a laptop lid
        // closed overnight should not wake to a thousand queued fetches.
        this.visibility = () => (document.hidden ? this.stop() : this.start());
        document.addEventListener('visibilitychange', this.visibility);
    }

    disconnect() {
        this.stop();
        if (this.visibility) document.removeEventListener('visibilitychange', this.visibility);
        this.visibility = null;
    }

    start() {
        if (!this.timer) this.timer = window.setInterval(() => this.refresh(), this.intervalValue);
    }

    stop() {
        if (this.timer) window.clearInterval(this.timer);
        this.timer = null;
    }

    async refresh() {
        let payload;
        try {
            const response = await fetch(this.urlValue, { headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            payload = await response.json();
        } catch {
            return; // silent, and the interval survives it
        }

        const strip = this.element.querySelector('[data-widget-id="nowbar"] .w-inner');
        if (strip && payload.strip) strip.innerHTML = payload.strip;

        // Dispatched ON each map plate rather than on the grid: a Stimulus action
        // listens on its own element or an ancestor, and the plates are below
        // this one. The map redraws what it is given and knows nothing about who
        // fetched it.
        if (payload.layers && payload.layers.length) {
            this.element.querySelectorAll('[data-controller~="overview-map"]').forEach((plate) => {
                plate.dispatchEvent(new CustomEvent('overview:layers', { detail: { layers: payload.layers } }));
            });
        }
    }
}
