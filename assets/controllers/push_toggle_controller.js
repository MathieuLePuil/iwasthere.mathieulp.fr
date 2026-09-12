import { Controller } from '@hotwired/stimulus';
import { csrfHeaders } from '../csrf.js';

/*
 * L'interrupteur des notifications push (Paramètres → Notifications).
 * Activer demande la permission, abonne le navigateur et envoie l'abonnement
 * au serveur ; désactiver retire l'abonnement navigateur.
 */
export default class extends Controller {
    static targets = ['toggle'];
    static values = { publicKey: String, url: String };

    connect() {
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js').catch(() => {});
        }

        if (!this.publicKeyValue || !('Notification' in window)) {
            this.toggleTarget.disabled = true;
            return;
        }
        if (Notification.permission === 'granted') {
            this.toggleTarget.checked = true;
        } else if (Notification.permission === 'denied') {
            this.toggleTarget.disabled = true;
        }
    }

    async change() {
        if (this.toggleTarget.checked) {
            const ok = await this.subscribe();
            if (!ok) this.toggleTarget.checked = false;
        } else {
            await this.unsubscribe();
        }
    }

    async subscribe() {
        if (!('serviceWorker' in navigator)) return false;
        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') return false;

            const reg = await navigator.serviceWorker.ready;
            let sub = await reg.pushManager.getSubscription();
            if (!sub) {
                sub = await reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: this.urlBase64ToUint8Array(this.publicKeyValue),
                });
            }

            const response = await fetch(this.urlValue, {
                method: 'POST',
                headers: csrfHeaders({ 'Content-Type': 'application/json' }),
                body: JSON.stringify(sub),
                credentials: 'same-origin',
            });
            return response.ok;
        } catch (e) {
            return false;
        }
    }

    async unsubscribe() {
        try {
            const reg = await navigator.serviceWorker.ready;
            const sub = await reg.pushManager.getSubscription();
            if (sub) await sub.unsubscribe();
            try { localStorage.removeItem('iwt-push-sent'); } catch (e) {}
        } catch (e) { /* rien à faire : le navigateur n'était pas abonné */ }
    }

    urlBase64ToUint8Array(b64) {
        const pad = '='.repeat((4 - b64.length % 4) % 4);
        const base64 = (b64 + pad).replace(/-/g, '+').replace(/_/g, '/');
        const raw = window.atob(base64);
        const out = new Uint8Array(raw.length);
        for (let i = 0; i < raw.length; ++i) out[i] = raw.charCodeAt(i);
        return out;
    }
}
