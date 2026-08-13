import { Controller } from '@hotwired/stimulus';

/*
 * The add-module catalogue modal: free-text search over module names plus category-pill filtering.
 * Both narrow the visible cards; a category heading + its grid hide when the category has no
 * matching cards.
 */
export default class extends Controller {
    static targets = ['search', 'pill', 'card', 'group'];

    connect() {
        this.category = 'All';
        this.apply();
    }

    search() {
        this.apply();
    }

    filter(event) {
        event.preventDefault();
        this.category = event.currentTarget.dataset.category;
        this.pillTargets.forEach((p) => p.classList.toggle('on', p === event.currentTarget));
        this.apply();
    }

    apply() {
        const q = (this.hasSearchTarget ? this.searchTarget.value : '').trim().toLowerCase();
        const shown = {};

        this.cardTargets.forEach((card) => {
            const matchesText = !q || card.dataset.name.includes(q);
            const matchesCat = this.category === 'All' || card.dataset.category === this.category;
            const visible = matchesText && matchesCat;
            card.style.display = visible ? '' : 'none';
            if (visible) shown[card.dataset.category] = true;
        });

        // Hide a category's heading + grid when it has no visible cards.
        this.groupTargets.forEach((g) => {
            g.style.display = shown[g.dataset.category] ? '' : 'none';
        });
    }
}
