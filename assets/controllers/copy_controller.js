import { Controller } from '@hotwired/stimulus';

/** Durée pendant laquelle le bouton confirme la copie avant de reprendre son libellé */
const FEEDBACK_MS = 1600;

/**
 * Copie le contenu d'un champ dans le presse-papier.
 *
 * L'API navigator.clipboard n'existe qu'en contexte sécurisé et peut être refusée :
 * en cas d'échec on sélectionne le texte, ce qui laisse au moins un copier manuel
 * possible plutôt qu'un bouton qui ne fait rien.
 */
export default class extends Controller {
    static targets = ['source', 'button'];

    async copy() {
        try {
            await navigator.clipboard.writeText(this.sourceTarget.value);
            this._flash('Copié !');
        } catch (_) {
            this.sourceTarget.select();
            this._flash('Sélectionné');
        }
    }

    _flash(label) {
        if (!this.hasButtonTarget) return;

        // Mémorisé au premier passage : sans ça, un double-tap dans la fenêtre de
        // retour figerait « Copié ! » comme libellé d'origine.
        this._label ??= this.buttonTarget.textContent;
        this.buttonTarget.textContent = label;

        clearTimeout(this._timer);
        this._timer = setTimeout(() => {
            this.buttonTarget.textContent = this._label;
        }, FEEDBACK_MS);
    }

    disconnect() {
        clearTimeout(this._timer);
    }
}
