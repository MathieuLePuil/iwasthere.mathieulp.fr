import { Controller } from '@hotwired/stimulus';

/**
 * Le mini-parcours guidé de complétion (le lendemain d'un événement).
 *
 * Une seule étape est visible à la fois ; le contrôleur gère l'avance, le retour,
 * la barre de progression et l'envoi final. La soumission passe par fetch (et non
 * un submit natif) pour révéler l'écran de fin « célébration » sans quitter la page.
 *
 * Le formulaire porte le contrôleur : `next`/`prev`/`finish` sont câblés sur les
 * boutons de chaque étape. Les champs restent de vrais inputs — un FormData suffit
 * à tout envoyer, photo comprise.
 */
export default class extends Controller {
    static targets = ['step', 'bar', 'current', 'total', 'photoPreview'];
    static values = { url: String, showUrl: String };

    connect() {
        // L'étape de fin (célébration) ne compte pas dans la progression : c'est la
        // récompense, pas une saisie. On la sort du décompte et de la navigation avant.
        this.inputSteps = this.stepTargets.filter((s) => !s.hasAttribute('data-completion-done'));
        this.doneStep = this.stepTargets.find((s) => s.hasAttribute('data-completion-done')) ?? null;
        this.index = 0;
        this.sending = false;

        if (this.hasTotalTarget) this.totalTarget.textContent = String(this.inputSteps.length);
        this.show(0);
    }

    /** Avance d'une étape ; sur la dernière saisie, déclenche l'envoi. */
    next() {
        if (!this.validate(this.inputSteps[this.index])) return;
        if (this.index >= this.inputSteps.length - 1) {
            this.finish();
            return;
        }
        this.show(this.index + 1);
    }

    prev() {
        if (this.index === 0) return;
        this.show(this.index - 1);
    }

    /** Étoile choisie : on laisse voir la sélection puis on enchaîne, c'est plus vivant. */
    onRate(event) {
        this.repaintStars();
        // Ne pas auto-avancer si on revient corriger une note déjà posée : l'utilisateur
        // veut peut-être juste la changer. On n'enchaîne que sur la première sélection.
        if (event?.currentTarget?.dataset.first === '1') {
            clearTimeout(this._rateTimer);
            this._rateTimer = setTimeout(() => {
                if (this.inputSteps[this.index]?.dataset.step === 'rating') this.next();
            }, 460);
        }
    }

    repaintStars() {
        const step = this.inputSteps[this.index];
        if (!step) return;
        const checked = step.querySelector('input[name="rating"]:checked');
        const upTo = checked ? parseInt(checked.value, 10) : 0;
        step.querySelectorAll('[data-star]').forEach((star, i) => {
            star.style.color = i < upTo ? 'var(--positive)' : 'var(--bg-3)';
            star.style.transform = i < upTo ? 'scale(1.06)' : 'scale(1)';
        });
        const hint = step.querySelector('[data-rating-hint]');
        if (hint) {
            const labels = ['', 'Bof', 'Pas mal', 'Bien', 'Super', 'Inoubliable'];
            hint.textContent = labels[upTo] || 'Touche une étoile';
            hint.style.color = upTo ? 'var(--positive)' : 'var(--fg-4)';
        }
    }

    /** Aperçu immédiat de la photo choisie. */
    onPhoto(event) {
        const file = event.currentTarget.files?.[0];
        if (!file || !this.hasPhotoPreviewTarget) return;
        const reader = new FileReader();
        reader.onload = (e) => {
            this.photoPreviewTarget.src = e.target.result;
            this.photoPreviewTarget.closest('[data-photo-frame]')?.removeAttribute('hidden');
        };
        reader.readAsDataURL(file);
    }

    /**
     * Le tennis se note du point de vue du vainqueur : un score seul ne dit pas qui a
     * gagné, la case est donc obligatoire dès qu'un score est saisi (même règle que
     * le serveur, doublée ici pour un retour immédiat).
     */
    validate(step) {
        this.clearError();
        if (!step || step.dataset.step !== 'result') return true;
        const scoreEl = step.querySelector('input[name="final_score"]');
        const winners = step.querySelectorAll('input[name="winner"]');
        const isTennis = step.dataset.type === 'tennis';
        if (isTennis && scoreEl && scoreEl.value.trim() !== '' && ![...winners].some((c) => c.checked)) {
            this.showError('Indique le vainqueur : un score de tennis ne permet pas de le déduire.');
            return false;
        }
        return true;
    }

    async finish() {
        if (this.sending) return;
        this.sending = true;
        this.setBusy(true);

        try {
            const res = await fetch(this.urlValue, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: new FormData(this.element),
            });
            const data = await res.json().catch(() => ({}));

            if (!res.ok || !data.ok) {
                this.setBusy(false);
                this.sending = false;
                // Le serveur peut renvoyer l'étape fautive (ex. vainqueur manquant)
                if (data.step) {
                    const i = this.inputSteps.findIndex((s) => s.dataset.step === data.step);
                    if (i >= 0) this.show(i);
                }
                this.showError(data.error || 'Un souci est survenu. Réessaie.');
                return;
            }

            this.celebrate();
        } catch (e) {
            this.setBusy(false);
            this.sending = false;
            this.showError('Connexion perdue. Réessaie dans un instant.');
        }
    }

    /** Révèle l'écran de fin ; les confettis s'animent en apparaissant. */
    celebrate() {
        this.stepTargets.forEach((s) => s.classList.remove('is-active'));
        if (this.hasBarTarget) this.barTarget.style.width = '100%';
        this.element.querySelector('[data-completion-chrome]')?.setAttribute('hidden', '');
        if (this.doneStep) {
            this.doneStep.classList.add('is-active');
            // Force le rejeu des keyframes de confettis à l'affichage
            this.doneStep.querySelectorAll('.cmpl-confetti span').forEach((c) => {
                c.style.animation = 'none';
                void c.offsetWidth;
                c.style.animation = '';
            });
        }
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    show(i) {
        this.index = i;
        this.stepTargets.forEach((s) => s.classList.remove('is-active'));
        const step = this.inputSteps[i];
        step.classList.add('is-active');

        if (this.hasBarTarget) {
            const pct = ((i + 1) / this.inputSteps.length) * 100;
            this.barTarget.style.width = pct + '%';
        }
        if (this.hasCurrentTarget) this.currentTarget.textContent = String(i + 1);

        if (step.dataset.step === 'rating') this.repaintStars();

        // Le focus part sur le premier champ texte, sauf pour la note (tactile)
        const focusable = step.querySelector('textarea, input[type="text"], input[type="number"]');
        if (focusable && step.dataset.step !== 'rating') {
            setTimeout(() => focusable.focus({ preventScroll: true }), 60);
        }
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    setBusy(on) {
        this.element.querySelectorAll('[data-completion-finish]').forEach((b) => {
            b.classList.toggle('btn-loading', on);
            b.disabled = on;
        });
    }

    /** L'erreur s'affiche dans l'étape visible — chacune porte son propre emplacement. */
    showError(msg) {
        const box = (this.inputSteps[this.index] ?? this.element).querySelector('[data-cmpl-error]');
        if (!box) return;
        box.textContent = msg;
        box.removeAttribute('hidden');
    }

    clearError() {
        this.element.querySelectorAll('[data-cmpl-error]').forEach((b) => b.setAttribute('hidden', ''));
    }
}
