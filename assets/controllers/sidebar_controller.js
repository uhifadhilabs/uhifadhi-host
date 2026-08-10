import { Controller } from '@hotwired/stimulus';

/*
 * Collapsible left navigation: toggles `.rail` on the sidebar root to shrink it to
 * an icon-only rail (Grafana-style), reclaiming width for maps and wide tables. The
 * choice is remembered in localStorage and re-applied on connect.
 */
export default class extends Controller {
    connect() {
        try {
            if (localStorage.getItem('uhifadhi-nav') === 'rail') {
                this.element.classList.add('rail');
            }
        } catch (e) {
            // localStorage unavailable — start expanded.
        }
    }

    toggle() {
        const rail = this.element.classList.toggle('rail');
        try {
            localStorage.setItem('uhifadhi-nav', rail ? 'rail' : 'full');
        } catch (e) {
            // localStorage unavailable — the toggle still works for this page view.
        }
    }
}
