import { Controller } from '@hotwired/stimulus';

/*
 * Le formulaire d'événement (création et édition) : ce qui se montre ou se
 * cache selon la date et le sport, et la règle du vainqueur au tennis.
 *
 * Un score de tennis est noté du point de vue du vainqueur : il ne dit pas qui
 * a gagné, la case est donc obligatoire dès qu'un score est saisi. On passe par
 * la validation native plutôt qu'un preventDefault — le navigateur bloque
 * l'envoi, affiche le message et cible la case tout seul.
 *
 * Les blocs sont retrouvés par id dans le formulaire : ils existent ou non selon
 * la catégorie, chaque méthode tolère leur absence.
 */
export default class extends Controller {
    connect() {
        const date = this.$('#input-date');
        if (date) this.dateChanged({ target: date });
        this.paintTypeButtons();
        this.syncWinnerRequired();
    }

    $(selector) {
        return this.element.querySelector(selector);
    }

    $$(selector) {
        return Array.from(this.element.querySelectorAll(selector));
    }

    /* La date décide si l'événement est passé : le bloc souvenir (score, note…) ne
       s'affiche que dans ce cas, et le verbe du bloc amis change. */
    dateChanged(event) {
        const value = event.target.value;
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const selected = value ? new Date(value + 'T00:00:00') : null;
        const isPast = !!selected && selected < today;

        this.$('#past-extra-fields')?.classList.toggle('hidden', !isPast);

        const verb = this.$('#friends-toggle-verb');
        if (verb) verb.textContent = isPast ? 'étais' : 'serai';

        const cat = this.$('#input-category')?.value ?? 'music';
        this.$('#duration-field-past')?.classList.toggle('hidden', cat !== 'music');
        this.$('#score-field-past')?.classList.toggle('hidden', cat !== 'sport');
        if (cat === 'sport') this.updateScoreFields();
        this.syncWinnerRequired();
    }

    /* Changement de sport : le tennis a son propre bloc de score (sets), et la
       sélection visuelle des boutons suit le bouton radio (édition). */
    typeChanged() {
        this.paintTypeButtons();
        this.updateScoreFields();
        this.syncWinnerRequired();
    }

    updateScoreFields() {
        const checked = this.$('#type-selector-sport input:checked') ?? this.$('input[name="type"]:checked');
        const isTennis = !!checked && checked.value === 'tennis';
        const dual = this.$('#score-dual');
        const tennis = this.$('#score-tennis');
        if (!dual || !tennis) return;
        dual.classList.toggle('hidden', isTennis);
        tennis.classList.toggle('hidden', !isTennis);
        this.syncWinnerLabels();
    }

    paintTypeButtons() {
        this.$$('[data-type-btn]').forEach((div) => {
            const radio = div.closest('label')?.querySelector('input[type="radio"]');
            if (!radio) return;
            div.classList.toggle('is-selected', radio.checked);
        });
    }

    /* Une seule case vainqueur : cocher l'une décoche l'autre. */
    exclusiveWinner(event) {
        const el = event.target;
        if (!el.checked) return;
        const scope = el.closest('.cmpl-winner') ?? this.element;
        scope.querySelectorAll('input[name="winner"]').forEach((c) => { if (c !== el) c.checked = false; });
        this.syncWinnerRequired();
    }

    syncWinnerRequired() {
        const boxes = this.$$('input[name="winner"]');
        const score = this.$('#input-final-score') ?? this.$('input[name="final_score"]');
        if (!boxes.length || !score) return;

        // offsetParent vaut null dès qu'un parent est masqué (autre sport, match à
        // venir) : exiger un champ invisible bloquerait l'envoi sans rien afficher.
        const shown = score.offsetParent !== null && boxes[0].offsetParent !== null;
        const type = this.$('input[name="type"]:checked');
        const isTennis = !type || type.value === 'tennis';
        const missing = shown && isTennis && score.value.trim() !== '' && !boxes.some((c) => c.checked);

        boxes.forEach((c) => {
            c.setCustomValidity(missing ? 'Indique le vainqueur : un score de tennis ne permet pas de le déduire.' : '');
        });
    }

    /* Les libellés des cases vainqueur reprennent les noms saisis. */
    syncWinnerLabels() {
        const t1 = this.$('input[name="team1"]');
        const t2 = this.$('input[name="team2"]');
        const l1 = this.$('#winner-label-team1');
        const l2 = this.$('#winner-label-team2');
        if (l1) l1.textContent = '🏆 ' + ((t1 && t1.value.trim()) || 'Joueur 1');
        if (l2) l2.textContent = '🏆 ' + ((t2 && t2.value.trim()) || 'Joueur 2');
    }
}
