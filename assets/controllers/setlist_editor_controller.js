import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['list'];

    add() {
        const div = document.createElement('div');
        div.className = 'flex items-center gap-2';
        div.innerHTML = `
            <input type="text" name="setlist[]" class="input-field text-sm flex-1" placeholder="Titre de la chanson">
            <button type="button" data-action="setlist-editor#remove"
                    class="w-8 h-8 flex items-center justify-center rounded-lg flex-shrink-0 transition-colors"
                    style="color:var(--fg-4)"
                    onmouseenter="this.style.color='var(--negative)';this.style.background='var(--negative-soft)'"
                    onmouseleave="this.style.color='var(--fg-4)';this.style.background='transparent'">
                ×
            </button>
        `;
        this.listTarget.appendChild(div);
        div.querySelector('input').focus();
    }

    remove(e) {
        e.currentTarget.closest('div').remove();
    }
}
