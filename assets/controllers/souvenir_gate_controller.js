import { Controller } from '@hotwired/stimulus';

/**
 * L'observateur de date des formulaires d'événement.
 *
 * Le souvenir (note, ressenti, score, setlist, durée…) n'a de sens qu'une fois
 * l'événement passé — même convention que `Event::isPast()` : une date strictement
 * antérieure à aujourd'hui. Tant que la date choisie est aujourd'hui ou dans le
 * futur, les champs « pastOnly » sont masqués **et désactivés**, pour qu'une note
 * saisie puis rendue future ne parte jamais au serveur.
 *
 * `connect()` évalue l'état au chargement (y compris sur une visite Turbo, où
 * `DOMContentLoaded` ne se rejoue pas), puis chaque changement de date le réévalue.
 */
export default class extends Controller {
    static targets = ['date', 'pastOnly'];

    connect() {
        this.update();
    }

    update() {
        const past = this.isPast();
        this.pastOnlyTargets.forEach((el) => {
            el.classList.toggle('hidden', !past);
            // Neutralise les champs cachés : un souvenir saisi puis repassé en « à venir »
            // ne doit pas être soumis. On restaure `disabled` à false quand la date redevient passée.
            el.querySelectorAll('input, textarea, select').forEach((field) => {
                field.disabled = !past;
            });
        });
    }

    /** Date strictement antérieure à aujourd'hui — comme Event::isPast(). */
    isPast() {
        if (!this.hasDateTarget || !this.dateTarget.value) return false;
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const selected = new Date(this.dateTarget.value + 'T00:00:00');
        return selected < today;
    }
}
