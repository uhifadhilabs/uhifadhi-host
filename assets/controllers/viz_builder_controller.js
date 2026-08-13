import { Controller } from '@hotwired/stimulus';

/*
 * The "add a visualization" builder: keeps the preview caption in sync with the Type / X / Y
 * selects, and lets a preset button fill those selects (+ the hidden title) and submit.
 */
export default class extends Controller {
    static targets = ['type', 'x', 'y', 'title', 'preview'];

    connect() {
        this.preview();
    }

    preview() {
        if (!this.hasPreviewTarget) return;
        const type = this.typeTarget.value;
        const x = this.xTarget.value;
        const y = this.yTarget.value;
        this.previewTarget.textContent = `Preview: ${y} vs ${x} · ${type}`;
    }

    preset(event) {
        const b = event.currentTarget.dataset;
        if (this.hasTypeTarget) this.typeTarget.value = b.type;
        if (this.hasXTarget) this.xTarget.value = b.x;
        if (this.hasYTarget) this.yTarget.value = b.y;
        if (this.hasTitleTarget) this.titleTarget.value = b.title;
        this.preview();
        this.element.requestSubmit();
    }
}
