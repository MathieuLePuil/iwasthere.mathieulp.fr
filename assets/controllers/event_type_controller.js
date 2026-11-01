import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['musicBtn', 'sportBtn'];

    connect() {
        // Radio buttons are outside this element (inside the <form>), use document scope
        document.querySelectorAll('#type-selector-music input, #type-selector-sport input').forEach(input => {
            input.addEventListener('change', () => this.updateTypeBtns());
        });
        this.updateTypeBtns();
    }

    selectMusic() {
        document.getElementById('input-category').value = 'music';
        const artistInput = document.querySelector('#music-fields input[name="artist_name"]');
        if (artistInput) artistInput.required = true;
        document.getElementById('music-fields').classList.remove('hidden');
        document.getElementById('sport-fields').classList.add('hidden');
        document.getElementById('type-selector-music').classList.remove('hidden');
        document.getElementById('type-selector-sport').classList.add('hidden');

        if (this.hasMusicBtnTarget) {
            this.musicBtnTarget.style.borderColor = 'var(--positive)';
            this.musicBtnTarget.style.background = 'var(--positive-soft)';
            this.sportBtnTarget.style.borderColor = 'var(--border-default)';
            this.sportBtnTarget.style.background = 'var(--bg-2)';
        }

        const musicRadios = document.querySelectorAll('#type-selector-music input[type="radio"]');
        if (![...musicRadios].some(r => r.checked)) {
            if (musicRadios[0]) musicRadios[0].checked = true;
        }
        const df = document.getElementById('duration-field-past');
        if (df) df.classList.remove('hidden');
        const sf = document.getElementById('score-field-past');
        if (sf) sf.classList.add('hidden');
        this.updateTypeBtns();
    }

    selectSport() {
        document.getElementById('input-category').value = 'sport';
        const artistInput = document.querySelector('#music-fields input[name="artist_name"]');
        if (artistInput) artistInput.required = false;
        document.getElementById('sport-fields').classList.remove('hidden');
        document.getElementById('music-fields').classList.add('hidden');
        document.getElementById('type-selector-sport').classList.remove('hidden');
        document.getElementById('type-selector-music').classList.add('hidden');

        if (this.hasSportBtnTarget) {
            this.sportBtnTarget.style.borderColor = 'var(--positive)';
            this.sportBtnTarget.style.background = 'var(--positive-soft)';
            this.musicBtnTarget.style.borderColor = 'var(--border-default)';
            this.musicBtnTarget.style.background = 'var(--bg-2)';
        }

        const sportRadios = document.querySelectorAll('#type-selector-sport input[type="radio"]');
        if (![...sportRadios].some(r => r.checked)) {
            if (sportRadios[0]) sportRadios[0].checked = true;
        }
        const df = document.getElementById('duration-field-past');
        if (df) df.classList.add('hidden');
        // Show score only if past date is already visible
        const pastFields = document.getElementById('past-extra-fields');
        const sf = document.getElementById('score-field-past');
        if (sf) sf.classList.toggle('hidden', !pastFields || pastFields.classList.contains('hidden'));
        this.updateTypeBtns();
        this.updateScorePlaceholder();
    }

    updateTypeBtns() {
        document.querySelectorAll('[data-type-btn]').forEach(div => {
            const radio = div.closest('label')?.querySelector('input[type="radio"]');
            if (!radio) return;
            div.style.borderColor = radio.checked ? 'var(--positive)' : 'var(--border-default)';
            div.style.background = radio.checked ? 'var(--positive-soft)' : 'var(--bg-2)';
        });
        this.updateScorePlaceholder();
    }

    updateScorePlaceholder() {
        const checked = document.querySelector('#type-selector-sport input:checked');
        if (!checked) return;

        const isTennis = checked.value === 'tennis';
        const dual = document.getElementById('score-dual');
        const tennis = document.getElementById('score-tennis');
        if (dual) dual.classList.toggle('hidden', isTennis);
        if (tennis) tennis.classList.toggle('hidden', !isTennis);

        if (!isTennis) {
            const label1 = document.getElementById('score-label-team1');
            const label2 = document.getElementById('score-label-team2');
            if (label1) label1.textContent = 'Équipe 1';
            if (label2) label2.textContent = 'Équipe 2';
        }

        // Winner checkbox labels live in the page's inline script
        if (typeof window.syncWinnerLabels === 'function') window.syncWinnerLabels();
        // Changer de sport masque ou révèle le bloc tennis : l'exigence de vainqueur doit
        // suivre. Sans ça, quitter le tennis laisse une contrainte sur des cases devenues
        // invisibles — le navigateur refuse l'envoi sans pouvoir afficher le message.
        if (typeof window.syncWinnerRequired === 'function') window.syncWinnerRequired();
    }
}
