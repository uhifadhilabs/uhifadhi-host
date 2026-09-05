import { Controller } from '@hotwired/stimulus';

/*
 * Viewer-timezone time display (Route A: store UTC, format in the browser).
 *
 * The server stores and emits instants in UTC and renders every human-facing patrol
 * timestamp as a machine <time datetime="…Z">…</time> whose inner text is the UTC
 * fallback (so with no JS the page still reads, just in UTC). This one root controller,
 * mounted on <body>, rewrites that inner text to the SIGNED-IN VIEWER'S OWN browser zone
 * — Intl.DateTimeFormat(undefined, …), no timeZone argument, so it follows whatever clock
 * the ranger's device is set to. Nobody's time is shown in a zone that matches no one.
 *
 * It couples to nothing: it finds `time[datetime]` by query, not by Stimulus targets, so
 * the module templates only have to emit semantic <time> — no data-action, no target wiring.
 * It survives Turbo (turbo:load / turbo:render) and client-side DOM swaps (the patrol
 * calendar fetches a new month and swaps it in without a Turbo navigation) via a
 * MutationObserver.
 *
 * Granularity is read from the element's own text, never guessed: a bare "06:10" stays a
 * time, "sat 22 aug · 06:10" stays date+time, the history rows' "06:10 · 22 aug" keep their
 * time-first order, and a stamp that carried a weekday keeps one. The original UTC text is
 * stashed on first pass so repeated runs are idempotent.
 */
export default class extends Controller {
    connect() {
        this.localise = this.localise.bind(this);
        this.localise();

        // Turbo swaps the <body>'s children on navigation; re-localise the new DOM.
        document.addEventListener('turbo:load', this.localise);
        document.addEventListener('turbo:render', this.localise);

        // Non-Turbo DOM insertions (e.g. the calendar swapping in a fetched month).
        this.observer = new MutationObserver((mutations) => {
            for (const m of mutations) {
                for (const node of m.addedNodes) {
                    if (node.nodeType !== Node.ELEMENT_NODE) continue;
                    if (node.matches?.('time[datetime]') || node.querySelector?.('time[datetime]')) {
                        this.localise();
                        return;
                    }
                }
            }
        });
        this.observer.observe(this.element, { childList: true, subtree: true });
    }

    disconnect() {
        document.removeEventListener('turbo:load', this.localise);
        document.removeEventListener('turbo:render', this.localise);
        this.observer?.disconnect();
    }

    localise() {
        for (const el of this.element.querySelectorAll('time[datetime]')) {
            this.localiseOne(el);
        }
    }

    localiseOne(el) {
        const iso = el.getAttribute('datetime');
        const when = new Date(iso);
        if (Number.isNaN(when.getTime())) return; // leave unparseable stamps as their UTC text

        // Stash the server's UTC text once so re-runs format from a stable source.
        if (el.dataset.localtimeUtc === undefined) {
            el.dataset.localtimeUtc = el.textContent.trim();
        }
        const source = el.dataset.localtimeUtc;

        const timeRe = /\d{1,2}:\d{2}/;
        const hasTime = timeRe.test(source);
        const withoutClock = source.replace(/\d{1,2}:\d{2}/g, '');
        // Any 3+ letter run is a month (or weekday) name → the stamp carried a date.
        const hasDate = /[a-z]{3,}/i.test(withoutClock);
        const hasWeekday = /\b(mon|tue|wed|thu|fri|sat|sun)\b/i.test(source);
        // Time-first when the clock appears before the first date word (the "06:10 · 22 aug" rows).
        const timeFirst = hasTime && hasDate
            && source.search(timeRe) < source.search(/[a-z]{3,}/i);

        const parts = [];
        if (hasDate) {
            const opts = { day: 'numeric', month: 'short' };
            if (hasWeekday) opts.weekday = 'short';
            parts.push(new Intl.DateTimeFormat(undefined, opts).format(when).toLowerCase());
        }
        if (hasTime) {
            parts.push(new Intl.DateTimeFormat(undefined, {
                hour: '2-digit', minute: '2-digit', hour12: false,
            }).format(when));
        }
        if (!parts.length) return;
        if (timeFirst) parts.reverse();

        el.textContent = parts.join(' · ');
    }
}
