import { Controller } from '@hotwired/stimulus';

/*
 * Reliable same-document View Transition for the in-page module forms (row toggle · pill × · + Add).
 * Instead of letting them full-page reload, it POSTs the form, fetches the resulting page, and swaps
 * this page's content inside document.startViewTransition — so the module, whose row and parked card
 * share a view-transition-name (mod-<slug>), morphs smoothly between the Active and Inactive columns.
 * No external library; where startViewTransition is unavailable it just swaps instantly.
 */
export default class extends Controller {
    connect() {
        this.onSubmit = this.onSubmit.bind(this);
        this.element.addEventListener('submit', this.onSubmit);
    }

    disconnect() {
        this.element.removeEventListener('submit', this.onSubmit);
    }

    async onSubmit(event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'post') return;
        event.preventDefault();

        let next;
        try {
            const res = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'fetch' },
                cache: 'no-store',
            });
            const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
            next = doc.querySelector('.page');
        } catch {
            next = null;
        }

        if (!next) {
            form.submit(); // fall back to a normal submit if anything went sideways
            return;
        }

        document.adoptNode(next);
        const swap = () => this.element.replaceWith(next);

        if (document.startViewTransition) {
            document.startViewTransition(swap);
        } else {
            swap();
        }
    }
}
