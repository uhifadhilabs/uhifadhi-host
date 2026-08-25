import { Controller } from '@hotwired/stimulus';

/*
 * The sidebar location tree (design ruling F). The server renders the tree already open at
 * your location; this controller owns nothing but the folding, at all three levels:
 *
 *   Areas ──▸ the whole tree      (toggleAreas)
 *   an area ──▸ its own tab group (toggleArea)
 *   Modules ──▸ the module under it (toggleSubtree)
 *
 * Two invariants, both from the design's own handlers: a caret NEVER navigates (it lives inside
 * the row's link, so the click is stopped), and every fold is honest both ways — anything closed
 * here reopens from the same caret. State is chrome only: nothing is persisted.
 */
export default class extends Controller {
    /** Areas' caret folds the entire area subtree. */
    toggleAreas(event) {
        const row = this.#row(event);
        const tree = row?.nextElementSibling;
        if (!tree?.classList.contains('ntree')) {
            return;
        }
        tree.classList.toggle('treehidden');
        row.classList.toggle('closed');
    }

    /** An area row's caret folds that area's own tab group. */
    toggleArea(event) {
        this.#fold(this.#row(event), 'nta-group', true);
    }

    /** A tab's caret folds only its child group (the module you are inside). */
    toggleSubtree(event) {
        this.#fold(this.#row(event), 'ntgroup', true);
    }

    /** The row a caret belongs to; the caret must never follow that row's link. */
    #row(event) {
        event.preventDefault();
        event.stopPropagation();

        return event.currentTarget.closest('a');
    }

    #fold(row, groupClass, markRow) {
        const group = row?.nextElementSibling;
        if (!group?.classList.contains(groupClass)) {
            return;
        }
        group.classList.toggle('closed');
        if (markRow) {
            row.classList.toggle('closed');
        }
    }
}
