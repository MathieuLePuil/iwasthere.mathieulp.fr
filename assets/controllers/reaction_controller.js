import { Controller } from '@hotwired/stimulus';

/** Distance de glissé vers le bas au-delà de laquelle la feuille se ferme */
const CLOSE_THRESHOLD = 90;

/**
 * La barre de réactions d'une participation : les emojis déjà posés, et un « + »
 * qui ouvre un sélecteur.
 *
 * La feuille se ferme au glissé vers le bas, au clic sur le voile ou avec Échap.
 * Pas de bouton de fermeture : contrairement aux feuilles de confirmation, choisir
 * un emoji est l'action, et rien ici n'engage à quoi que ce soit.
 *
 * Le sélecteur propose des raccourcis mais n'enferme pas : le champ libre accepte
 * n'importe quel emoji, celui du clavier système comme un copier-coller. C'est le
 * serveur qui tranche ce qui en est un — ici on ne fait que refuser le vide.
 *
 * Taper une pastille existante bascule sa propre réaction, et repeint tout de
 * suite : un compteur qui attend le serveur donne l'impression que le tap n'a pas
 * pris. Le fragment renvoyé fait ensuite foi — il corrige la supposition du client
 * et rattrape au passage les réactions posées entre-temps par quelqu'un d'autre.
 * Si l'appel échoue, rien n'est persisté et on remet ce qui était affiché.
 */
export default class extends Controller {
    static values = { url: String, csrf: String, suggestions: Array };
    static targets = ['pills'];

    toggle(event) {
        const button = event.currentTarget;
        const emoji = event.params.emoji;
        const before = this.pillsTarget.innerHTML;

        this._paintOptimistically(button);
        this._send(emoji, before);
    }

    /** Depuis le sélecteur : la pastille n'existe peut-être pas encore, on attend le serveur. */
    pick(emoji) {
        this._send(emoji, this.pillsTarget.innerHTML);
    }

    async _send(emoji, previousHtml) {
        const body = new FormData();
        body.append('emoji', emoji);
        body.append('_token', this.csrfValue);

        try {
            const res = await fetch(this.urlValue, { method: 'POST', body, credentials: 'same-origin' });
            if (!res.ok) throw new Error(res.status);
            const { html } = await res.json();
            this.pillsTarget.innerHTML = html;
        } catch (_) {
            this.pillsTarget.innerHTML = previousHtml;
        }
    }

    /** Le compteur bouge d'un cran dans le sens du tap, en attendant le fragment. */
    _paintOptimistically(button) {
        const mine = button.getAttribute('aria-pressed') === 'true';
        const countEl = button.querySelector('.iwt-reaction-count');
        const count = (parseInt(countEl.textContent, 10) || 0) + (mine ? -1 : 1);

        // Dernière réaction retirée : la pastille n'a plus lieu d'être
        if (count <= 0) {
            button.remove();
            return;
        }

        countEl.textContent = String(count);
        button.classList.toggle('is-mine', !mine);
        button.setAttribute('aria-pressed', mine ? 'false' : 'true');
    }

    // ── Sélecteur ──────────────────────────────────────────────────────────────

    open() {
        // Un double-tap ouvrirait deux feuilles avant que la première ne soit peinte,
        // et la seconde abandonnerait un voile que plus rien ne ferme.
        if (this.sheet) return;

        this.backdrop = document.createElement('div');
        this.backdrop.className = 'iwt-sheet-backdrop';
        this.backdrop.addEventListener('click', () => this.close());

        this.sheet = document.createElement('div');
        // --draggable coupe le défilement tactile sur la feuille, sans quoi le
        // glissé ferait bouger la page derrière au lieu de la feuille.
        this.sheet.className = 'iwt-sheet iwt-sheet--draggable';
        this.sheet.setAttribute('role', 'dialog');
        this.sheet.setAttribute('aria-modal', 'true');
        this.sheet.innerHTML = `
            <div class="iwt-sheet-grabber"></div>
            <p class="iwt-sheet-title">Réagir</p>
            <div class="iwt-emoji-grid" data-role="grid"></div>
            <form class="iwt-emoji-custom" data-role="form">
                <input type="text" data-role="input" class="input-field" placeholder="Ou le tien : 🦄"
                       autocomplete="off" autocapitalize="off" maxlength="16" aria-label="Ton emoji">
                <button type="submit" class="btn-primary px-4">Ajouter</button>
            </form>`;

        this._bindDrag(this.sheet);

        const grid = this.sheet.querySelector('[data-role="grid"]');
        this.suggestionsValue.forEach((emoji) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'iwt-emoji-choice';
            // textContent : l'emoji vient de la config, mais rien ne justifie d'ouvrir
            // une porte à du HTML pour trois caractères.
            button.textContent = emoji;
            button.addEventListener('click', () => {
                this.close();
                this.pick(emoji);
            });
            grid.append(button);
        });

        const form = this.sheet.querySelector('[data-role="form"]');
        const input = this.sheet.querySelector('[data-role="input"]');
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const emoji = input.value.trim();
            if (!emoji) return;
            this.close();
            this.pick(emoji);
        });

        this.onKeydown = (e) => { if (e.key === 'Escape') this.close(); };
        document.addEventListener('keydown', this.onKeydown);

        document.body.append(this.backdrop, this.sheet);
        // Un frame avant .is-open, sinon le navigateur peint l'état final sans transition.
        requestAnimationFrame(() => {
            this.backdrop.classList.add('is-open');
            this.sheet.classList.add('is-open');
        });
    }

    /**
     * Fermeture au glissé vers le bas, comme une feuille native.
     *
     * Le geste est ignoré s'il part d'un champ ou d'un bouton : sans ça, un doigt
     * qui ripe d'un pixel en tapant un emoji emmènerait la feuille avec lui.
     * Au-delà du seuil on ferme, sinon elle revient d'elle-même.
     */
    _bindDrag(sheet) {
        let startY = null;
        let offset = 0;

        const down = (event) => {
            if (event.target.closest('input, button')) return;
            startY = event.clientY;
            offset = 0;
            // Pas de transition pendant le geste : la feuille doit coller au doigt
            sheet.style.transition = 'none';
            sheet.setPointerCapture(event.pointerId);
        };

        const move = (event) => {
            if (startY === null) return;
            // Jamais vers le haut : une feuille collée en bas ne se tire pas
            offset = Math.max(0, event.clientY - startY);
            sheet.style.transform = `translateY(${offset}px)`;
        };

        const up = () => {
            if (startY === null) return;
            const close = offset > CLOSE_THRESHOLD;
            startY = null;

            if (close) {
                this.close();
                return;
            }

            // En deçà du seuil : on rend la main au CSS, qui ramène la feuille
            sheet.style.transition = '';
            sheet.style.transform = '';
        };

        sheet.addEventListener('pointerdown', down);
        sheet.addEventListener('pointermove', move);
        sheet.addEventListener('pointerup', up);
        sheet.addEventListener('pointercancel', up);
    }

    close() {
        if (!this.sheet) return;

        document.removeEventListener('keydown', this.onKeydown);
        const { sheet, backdrop } = this;
        this.sheet = null;
        this.backdrop = null;

        // Les styles posés par le glissé priment sur la classe : sans ce ménage,
        // la feuille resterait figée là où le doigt l'a laissée.
        sheet.style.transition = '';
        sheet.style.transform = '';
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

    disconnect() {
        this.close();
    }
}
