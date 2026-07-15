import { Controller } from '@hotwired/stimulus';

/**
 * Applique le thème tout de suite puis l'enregistre en tâche de fond : attendre
 * l'aller-retour serveur pour repeindre donnerait un réglage qui traîne.
 */
export default class extends Controller {
    static values = { url: String };

    select(event) {
        const theme = event.params.theme;
        const previous = document.documentElement.dataset.themePref;

        this._apply(theme);
        this._save(theme, previous);
    }

    _apply(theme) {
        document.documentElement.dataset.themePref = theme;
        window.iwtApplyTheme();
    }

    async _save(theme, previous) {
        const body = new FormData();
        body.append('theme', theme);

        try {
            const res = await fetch(this.urlValue, { method: 'POST', body, credentials: 'same-origin' });
            if (!res.ok) throw new Error(res.status);
        } catch (_) {
            // Rien n'est persisté : on remet l'ancien thème plutôt que de laisser
            // l'écran mentir sur ce qui sera rechargé au prochain passage. La case se
            // coche sans click() : ce dernier rejouerait select() et donc _save().
            this._apply(previous);
            const input = this.element.querySelector(`input[value="${previous}"]`);
            if (input) input.checked = true;
        }
    }
}
