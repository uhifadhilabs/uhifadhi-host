import { Controller } from '@hotwired/stimulus';

/*
 * The sidebar location tree. The server renders it already open at your location;
 * this controller owns nothing but the folding.
 *
 * ONE HANDLER FOR EVERY DEPTH. The shell renders one row grammar at all three
 * levels — a row, then the group of its children directly after it — so folding
 * is one behaviour rather than three near-copies that had to be kept in step.
 *
 * Two invariants: a caret NEVER navigates (it lives inside the row's link, so the
 * click is stopped), and every fold is honest both ways — the children stay in the
 * document, so anything closed here reopens from the same caret. State is chrome
 * only: nothing is persisted.
 */
export default class extends Controller {
    fold(event) {
        event.preventDefault();
        event.stopPropagation();

        const row = event.currentTarget.parentElement;
        const group = row?.nextElementSibling;
        if (!group) {
            return;
        }

        group.classList.toggle('closed');
        row.classList.toggle('closed');
    }
}
