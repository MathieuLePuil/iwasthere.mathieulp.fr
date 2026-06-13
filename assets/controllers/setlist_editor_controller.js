import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['list', 'encoreList'];

    add() {
        const div = this._makeRow('setlist[]');
        this.listTarget.appendChild(div);
        div.querySelector('input').focus();
    }

    remove(e) {
        e.currentTarget.closest('div').remove();
    }

    addEncore() {
        if (!this.hasEncoreListTarget) return;
        const div = this._makeRow('setlist_encores[]');
        this.encoreListTarget.appendChild(div);
        div.querySelector('input').focus();
    }

    removeEncore(e) {
        e.currentTarget.closest('div').remove();
    }

    _makeRow(name) {
        const div = document.createElement('div');
        div.className = 'flex items-center gap-2';
        div.innerHTML = `
            <input type="text" name="${name}" class="input-field text-sm flex-1" placeholder="Titre de la chanson">
            <button type="button"
                    data-action="setlist-editor#${name.startsWith('setlist_encores') ? 'removeEncore' : 'remove'}"
                    class="w-8 h-8 flex items-center justify-center rounded-lg flex-shrink-0 transition-colors"
                    style="color:var(--fg-4)"
                    onmouseenter="this.style.color='var(--negative)';this.style.background='var(--negative-soft)'"
                    onmouseleave="this.style.color='var(--fg-4)';this.style.background='transparent'">
                ×
            </button>
        `;
        return div;
    }
}
