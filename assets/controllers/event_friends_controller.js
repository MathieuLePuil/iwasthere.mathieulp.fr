import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['toggle', 'panel', 'nameInput', 'tagList', 'appCheckbox'];

    connect() {
        this._externalNames = [];

        // Pre-populate external friends that were rendered as hidden inputs (edit mode)
        this.element.querySelectorAll('input[data-prefill-external]').forEach(input => {
            const name = input.value;
            if (name && !this._externalNames.includes(name)) {
                this._externalNames.push(name);
                this._renderTag(name);
            }
            input.remove();
        });

        // If any friends are pre-selected, open the panel
        const hasPreSelected = this.hasAppCheckboxTarget &&
            this.appCheckboxTargets.some(cb => cb.checked);
        const hasExternal = this._externalNames.length > 0;
        if (hasPreSelected || hasExternal) {
            this.panelTarget.classList.remove('hidden');
            this.toggleTarget.setAttribute('aria-expanded', 'true');
        }
    }

    appCheckboxTargetConnected(cb) {
        cb.addEventListener('change', () => this._updateChipStyle(cb));
        this._updateChipStyle(cb);
    }

    toggle() {
        const open = !this.panelTarget.classList.contains('hidden');
        this.panelTarget.classList.toggle('hidden', open);
        this.toggleTarget.setAttribute('aria-expanded', String(!open));
    }

    addExternal(e) {
        e.preventDefault();
        const input = this.nameInputTarget;
        const name = input.value.trim();
        if (!name || this._externalNames.includes(name)) return;
        this._externalNames.push(name);
        this._renderTag(name);
        input.value = '';
        input.focus();
    }

    handleEnter(e) {
        if (e.key === 'Enter') this.addExternal(e);
    }

    removeExternal(e) {
        const name = e.currentTarget.dataset.name;
        this._externalNames = this._externalNames.filter(n => n !== name);
        e.currentTarget.closest('.iwt-friend-tag').remove();
    }

    _updateChipStyle(checkbox) {
        const visual = checkbox.nextElementSibling;
        if (!visual) return;
        if (checkbox.checked) {
            visual.style.borderColor = 'var(--positive)';
            visual.style.background = 'var(--positive-soft)';
            visual.style.color = 'var(--positive)';
        } else {
            visual.style.borderColor = 'var(--border-default)';
            visual.style.background = 'var(--bg-2)';
            visual.style.color = 'var(--fg-2)';
        }
    }

    _renderTag(name) {
        const tag = document.createElement('div');
        tag.className = 'iwt-friend-tag inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-medium';
        tag.style.cssText = 'background: var(--bg-3); color: var(--fg-2);';
        tag.innerHTML = `
            <span>${this._esc(name)}</span>
            <input type="hidden" name="friends_external[]" value="${this._esc(name)}">
            <button type="button"
                    data-action="click->event-friends#removeExternal"
                    data-name="${this._esc(name)}"
                    class="opacity-50 hover:opacity-100 transition-opacity leading-none">✕</button>
        `;
        this.tagListTarget.appendChild(tag);
    }

    _esc(str) {
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
}
