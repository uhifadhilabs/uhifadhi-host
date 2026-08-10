import { Controller } from '@hotwired/stimulus';

/*
 * Survey-plate theme: light (bone paper) is the default; dark (night canvas) is
 * opt-in. On boot the saved choice — or a `?theme=light|dark` URL override — is
 * applied by toggling `.dark` on <html>; toggle() flips and remembers it.
 * (Applied at Stimulus connect, so opt-in dark may flash briefly on load.)
 */
export default class extends Controller {
    connect() {
        try {
            const override = new URLSearchParams(window.location.search).get('theme');
            const choice = override ?? localStorage.getItem('uhifadhi-theme');
            document.documentElement.classList.toggle('dark', choice === 'dark');
        } catch (e) {
            // localStorage / URL unavailable — leave the default light surface.
        }
    }

    toggle() {
        const isDark = document.documentElement.classList.toggle('dark');
        try {
            localStorage.setItem('uhifadhi-theme', isDark ? 'dark' : 'light');
        } catch (e) {
            // localStorage unavailable — the toggle still works for this page view.
        }
    }
}
