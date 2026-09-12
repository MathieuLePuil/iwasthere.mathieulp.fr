import { Controller } from '@hotwired/stimulus';

/* Inviter un ami à plusieurs événements : « tout cocher », compteur, bouton
   d'envoi désactivé tant que rien n'est coché. */
export default class extends Controller {
    static targets = ['all', 'box', 'count', 'submit'];

    connect() {
        this.refresh();
    }

    toggleAll() {
        this.boxTargets.forEach((b) => { b.checked = this.allTarget.checked; });
        this.refresh();
    }

    refresh() {
        const checked = this.boxTargets.filter((b) => b.checked).length;
        this.countTarget.textContent = checked + ' / ' + this.boxTargets.length;
        this.allTarget.checked = checked === this.boxTargets.length && checked > 0;
        this.submitTarget.disabled = checked === 0;
    }
}
