import { Controller } from '@hotwired/stimulus';

/**
 * Confirmation avant envoi d'un formulaire, en bottom sheet plutôt qu'en confirm()
 * natif (qui affiche l'URL du site et casse l'illusion d'app).
 *
 * <form data-controller="confirm" data-action="submit->confirm#check"
 *       data-confirm-title-value="Supprimer cet événement ?">
 */
export default class extends Controller {
    static values = {
        title: String,
        body: { type: String, default: '' },
        label: { type: String, default: 'Confirmer' },
        cancel: { type: String, default: 'Annuler' },
        variant: { type: String, default: 'danger' },
    };

    check(event) {
        // Deuxième passage : la confirmation est acquise, on laisse filer vers Turbo.
        if (this.confirmed) return;

        event.preventDefault();
        this.open();
    }

    disconnect() {
        this.close();
    }

    open() {
        // Un double-tap envoie deux submit avant que le voile ne soit peint : sans ce garde,
        // la seconde feuille écrase les références et abandonne un voile plein écran
        // que plus rien ne ferme.
        if (this.sheet) return;

        this.backdrop = document.createElement('div');
        this.backdrop.className = 'iwt-sheet-backdrop';
        this.backdrop.addEventListener('click', () => this.close());

        this.sheet = document.createElement('div');
        this.sheet.className = 'iwt-sheet';
        this.sheet.setAttribute('role', 'dialog');
        this.sheet.setAttribute('aria-modal', 'true');
        this.sheet.innerHTML = `
            <div class="iwt-sheet-grabber"></div>
            <p class="iwt-sheet-title"></p>
            <p class="iwt-sheet-body"></p>
            <div class="iwt-sheet-actions">
                <button type="button" class="btn-${this.variantValue}" data-role="accept"></button>
                <button type="button" class="btn-secondary" data-role="cancel"></button>
            </div>`;

        // textContent et non innerHTML : les libellés portent des noms saisis par
        // l'utilisateur (« Supprimer Untel de tes amis ? »).
        const title = this.sheet.querySelector('.iwt-sheet-title');
        const body = this.sheet.querySelector('.iwt-sheet-body');
        const accept = this.sheet.querySelector('[data-role="accept"]');
        const cancel = this.sheet.querySelector('[data-role="cancel"]');

        title.textContent = this.titleValue;
        body.textContent = this.bodyValue;
        body.hidden = !this.bodyValue;
        accept.textContent = this.labelValue;
        cancel.textContent = this.cancelValue;

        accept.addEventListener('click', () => this.accept());
        cancel.addEventListener('click', () => this.close());

        this.onKeydown = (e) => { if (e.key === 'Escape') this.close(); };
        document.addEventListener('keydown', this.onKeydown);

        document.body.append(this.backdrop, this.sheet);
        // Un frame avant .is-open, sinon le navigateur peint l'état final sans transition.
        requestAnimationFrame(() => {
            this.backdrop.classList.add('is-open');
            this.sheet.classList.add('is-open');
            cancel.focus();
        });
    }

    accept() {
        this.confirmed = true;
        this.close();
        // requestSubmit et non submit() : ce dernier n'émet pas d'événement submit,
        // Turbo ne verrait pas partir le formulaire et la page ferait un rechargement complet.
        this.element.requestSubmit();
        // L'événement submit est distribué de façon synchrone : une fois requestSubmit revenu,
        // check() a déjà relu le drapeau. On le rearme, sinon un envoi qui ne navigue pas
        // (erreur réseau, Turbo qui garde la page) laisserait le formulaire sans confirmation.
        this.confirmed = false;
    }

    close() {
        if (!this.sheet) return;

        document.removeEventListener('keydown', this.onKeydown);
        const { sheet, backdrop } = this;
        this.sheet = null;
        this.backdrop = null;

        sheet.classList.remove('is-open');
        backdrop.classList.remove('is-open');

        const remove = () => { sheet.remove(); backdrop.remove(); };
        // Sans transition (prefers-reduced-motion), transitionend ne partirait jamais
        // et la feuille resterait dans le DOM à intercepter les clics.
        if (parseFloat(getComputedStyle(sheet).transitionDuration) > 0) {
            sheet.addEventListener('transitionend', remove, { once: true });
        } else {
            remove();
        }
    }
}
