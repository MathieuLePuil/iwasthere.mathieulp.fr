import { Controller } from '@hotwired/stimulus';

/*
 * Bannière d'installation PWA, pour les utilisateurs qui naviguent encore dans le
 * navigateur (pas en mode standalone). Deux parcours, car l'installation diffère :
 *   · Android/Chromium : l'événement `beforeinstallprompt` permet un vrai bouton
 *     « Installer » qui déclenche l'invite native.
 *   · iOS/Safari : aucune API — on affiche le tuto « Partager → Sur l'écran
 *     d'accueil » avec l'icône de partage.
 * La bannière est masquée en standalone, après un rejet récent, et sur les
 * navigateurs qui ne savent pas installer (autre chose que Safari iOS ou Chromium).
 *
 * L'événement `beforeinstallprompt` est capté très tôt dans le <head> (il peut se
 * déclencher avant que Stimulus ne connecte ce contrôleur) ; on relit donc
 * `window.__iwtInstallPrompt` au connect en plus d'écouter `iwt:installable`.
 */
export default class extends Controller {
    static targets = ['ios', 'android'];

    // Délai avant de reproposer la bannière après un rejet (14 jours).
    static DISMISS_DAYS = 14;
    static STORAGE_KEY = 'iwt-install-dismissed';

    connect() {
        if (this.isStandalone() || this.recentlyDismissed()) {
            return;
        }

        this.deferredPrompt = window.__iwtInstallPrompt || null;

        if (this.deferredPrompt) {
            this.showAndroid();
        } else if (this.isInstallableIos()) {
            this.showIos();
        } else {
            // Chromium sans événement encore reçu : on attend qu'il arrive.
            this.onInstallable = () => {
                this.deferredPrompt = window.__iwtInstallPrompt || null;
                if (this.deferredPrompt && !this.isStandalone() && !this.recentlyDismissed()) {
                    this.showAndroid();
                }
            };
            window.addEventListener('iwt:installable', this.onInstallable);
        }

        // Une installation réussie (ou un passage en standalone) doit escamoter la bannière.
        this.onInstalled = () => this.element.remove();
        window.addEventListener('appinstalled', this.onInstalled);
    }

    disconnect() {
        if (this.onInstallable) window.removeEventListener('iwt:installable', this.onInstallable);
        if (this.onInstalled) window.removeEventListener('appinstalled', this.onInstalled);
    }

    showIos() {
        // Deux éléments portent la cible « ios » (texte + bouton fermer) : tout révéler.
        this.iosTargets.forEach((el) => { el.hidden = false; });
        this.reveal();
    }

    showAndroid() {
        this.androidTargets.forEach((el) => { el.hidden = false; });
        this.reveal();
    }

    reveal() {
        this.element.hidden = false;
        // Force un reflow avant d'ajouter la classe, pour que la transition d'entrée joue.
        requestAnimationFrame(() => this.element.classList.add('is-visible'));
    }

    async install() {
        if (!this.deferredPrompt) return;
        this.deferredPrompt.prompt();
        try {
            await this.deferredPrompt.userChoice;
        } catch (e) { /* l'utilisateur a fermé l'invite */ }
        this.deferredPrompt = null;
        window.__iwtInstallPrompt = null;
        this.dismiss();
    }

    dismiss() {
        try {
            window.localStorage.setItem(this.constructor.STORAGE_KEY, String(Date.now()));
        } catch (e) { /* localStorage indisponible (mode privé) : rejet éphémère */ }
        this.element.classList.remove('is-visible');
        setTimeout(() => this.element.remove(), 250);
    }

    // ── Détection ──

    isStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true;
    }

    recentlyDismissed() {
        try {
            const ts = Number(window.localStorage.getItem(this.constructor.STORAGE_KEY));
            if (!ts) return false;
            const days = (Date.now() - ts) / (1000 * 60 * 60 * 24);
            return days < this.constructor.DISMISS_DAYS;
        } catch (e) {
            return false;
        }
    }

    // iOS/iPadOS uniquement, et seulement dans Safari (les autres navigateurs iOS ne
    // savent pas ajouter à l'écran d'accueil). L'iPad récent se présente en MacIntel
    // tactile, d'où le second test.
    isInstallableIos() {
        const ua = window.navigator.userAgent;
        const isIos = /iphone|ipad|ipod/i.test(ua)
            || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        if (!isIos) return false;
        const isSafari = !/crios|fxios|edgios|opios/i.test(ua);
        return isSafari;
    }
}
