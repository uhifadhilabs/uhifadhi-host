import { Controller } from '@hotwired/stimulus';

/*
 * The theme toggle, and ONLY the toggle.
 *
 * Applying the theme is not this controller's job any more and must not become it
 * again: the shell resolves the choice in an inline script in the document head,
 * before the first paint. A controller connects after the first frame, so a
 * visitor who chose dark used to be shown a white page first — which is the whole
 * defect the shell's pre-paint script fixed.
 *
 * The key is the shell's published one (Uhifadhi\Shell\Service\Theme::CHOICE_KEY):
 * the script reads it, this writes it, and neither invents its own name.
 */
export default class extends Controller {
    toggle() {
        const isDark = document.documentElement.classList.toggle('dark');
        try {
            localStorage.setItem('shell-theme', isDark ? 'dark' : 'light');
        } catch (e) {
            // Storage unavailable — the toggle still works for this page view.
        }
    }
}
