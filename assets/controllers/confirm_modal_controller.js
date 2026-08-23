import { Controller } from '@hotwired/stimulus';

/*
 * Shared "are you sure?" dialog — a PLATFORM component, not a page's own.
 *
 * Any destructive action in the app or in a module bundle attaches this and gets
 * the same dialog: same shape, same keyboard behaviour, same danger styling. It
 * replaces window.confirm(), which cannot be styled, cannot say more than one
 * line, and reads as a browser artefact rather than as this app.
 *
 * Usage — the element that triggers the action carries the controller:
 *
 *   <button data-controller="confirm-modal"
 *           data-action="click->confirm-modal#ask"
 *           data-confirm-modal-title-value="Reset your widget layout?"
 *           data-confirm-modal-message-value="…what will be lost…"
 *           data-confirm-modal-confirm-label-value="Reset layout"
 *           data-confirm-modal-danger-value="true">Reset</button>
 *
 * On confirmation it dispatches `confirm-modal:confirmed` ON THE TRIGGER, so the
 * caller does the work and this controller never knows what the action was:
 *
 *   button.addEventListener('confirm-modal:confirmed', () => …);
 *
 * A cancel dispatches `confirm-modal:cancelled`. A trigger that is a submit
 * button or a link still works: ask() suppresses the default action and, if the
 * caller adds no listener, replays the original activation after confirmation.
 *
 * Built on <dialog>: the platform gives us the modal focus trap, the top layer,
 * inert background and Escape-to-close for free — a hand-rolled trap is the part
 * everyone gets wrong. Escape closes (cancel), a backdrop click cancels, and
 * focus returns to the trigger, which <dialog> also handles.
 */
export default class extends Controller {
    static values = {
        title: { type: String, default: 'Are you sure?' },
        message: { type: String, default: '' },
        confirmLabel: { type: String, default: 'Confirm' },
        cancelLabel: { type: String, default: 'Cancel' },
        danger: { type: Boolean, default: false },
    };

    ask(event) {
        // The dialog answers asynchronously, so the original activation must not
        // proceed now — it is replayed on confirm if nobody handled the event.
        if (event) {
            event.preventDefault();
        }

        this.dialog = this.#build();
        document.body.appendChild(this.dialog);
        this.dialog.showModal();

        // Cancel is focused, not confirm: the safe answer should be the one a
        // stray Return key gives.
        this.dialog.querySelector('[data-confirm-cancel]').focus();
    }

    #build() {
        const dialog = document.createElement('dialog');
        dialog.className = 'confirm-modal';
        dialog.setAttribute('aria-labelledby', 'confirm-modal-title');

        const card = document.createElement('div');
        card.className = 'confirm-modal-card';

        const head = document.createElement('div');
        head.className = 'confirm-modal-head';

        const icon = document.createElement('span');
        icon.className = this.dangerValue ? 'confirm-modal-icon danger' : 'confirm-modal-icon';
        // Lucide triangle-alert, inline like every other icon in the app.
        icon.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>';

        const heading = document.createElement('h2');
        heading.className = 'confirm-modal-title';
        heading.id = 'confirm-modal-title';
        heading.textContent = this.titleValue;

        head.append(icon, heading);
        card.appendChild(head);

        if (this.messageValue) {
            const message = document.createElement('p');
            message.className = 'confirm-modal-message';
            message.textContent = this.messageValue;
            card.appendChild(message);
        }

        const actions = document.createElement('div');
        actions.className = 'confirm-modal-actions';

        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'tgl';
        cancel.textContent = this.cancelLabelValue;
        cancel.setAttribute('data-confirm-cancel', '');
        cancel.addEventListener('click', () => this.#close('cancelled'));

        const confirm = document.createElement('button');
        confirm.type = 'button';
        confirm.className = this.dangerValue ? 'cta confirm-modal-danger' : 'cta';
        confirm.textContent = this.confirmLabelValue;
        confirm.addEventListener('click', () => this.#close('confirmed'));

        actions.append(cancel, confirm);
        card.appendChild(actions);
        dialog.appendChild(card);

        // Escape (and any other native close) counts as a cancel.
        dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            this.#close('cancelled');
        });

        // Clicking the backdrop cancels. The backdrop IS the dialog element —
        // anything inside the card stops here.
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                this.#close('cancelled');
            }
        });

        return dialog;
    }

    #close(outcome) {
        this.dialog.close();
        this.dialog.remove();
        this.dialog = null;

        const event = new CustomEvent(`confirm-modal:${outcome}`, { bubbles: true, cancelable: true });
        const handled = !this.element.dispatchEvent(event);

        // Nobody prevented it and the trigger is a link or a submit button: the
        // person said yes, so replay what their click would have done.
        if ('confirmed' === outcome && !handled) {
            this.#replay();
        }
    }

    #replay() {
        const el = this.element;
        if (el.form && 'submit' === el.type) {
            el.form.requestSubmit(el);
        } else if ('A' === el.tagName && el.href) {
            window.location.href = el.href;
        }
    }
}
