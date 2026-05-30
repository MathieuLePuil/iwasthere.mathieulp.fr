import { Controller } from '@hotwired/stimulus';

/**
 * Manages the push notification toggle in settings.
 * Reflects real browser permission state — never the DB value.
 */
export default class extends Controller {
    static targets = ['btn', 'status', 'row'];
    static values  = { publicKey: String };

    async connect() {
        if (!this.#supported()) { this.element.style.display = 'none'; return; }
        await this.#refresh();
    }

    async toggle() {
        this.#setLoading();
        try {
            const sub = await this.#getSub();
            if (sub) {
                await this.#unsubscribe(sub);
                this.#render(false, false);
            } else {
                await this.#requestAndSubscribe();
            }
        } catch (e) {
            console.warn('Push settings toggle error:', e);
            await this.#refresh();
        }
    }

    // ── Private ─────────────────────────────────────────────────────────────

    async #refresh() {
        if (Notification.permission === 'denied') {
            this.#render(false, true);
            return;
        }
        const sub = await this.#getSub();
        this.#render(!!sub, false);
    }

    async #requestAndSubscribe() {
        const perm = await Notification.requestPermission();
        if (perm === 'denied') { this.#render(false, true); return; }
        if (perm !== 'granted') { this.#render(false, false); return; }
        await this.#subscribe();
        this.#render(true, false);
    }

    async #subscribe() {
        const reg = await this.#swReady();
        const sub = await reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: this.#b64(this.publicKeyValue),
        });
        await fetch('/push/subscribe', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(sub.toJSON()),
        });
    }

    async #unsubscribe(sub) {
        await sub.unsubscribe();
        await fetch('/push/unsubscribe', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ endpoint: sub.endpoint }),
        });
    }

    async #getSub() {
        try {
            const reg = await this.#swReady();
            return await reg.pushManager.getSubscription();
        } catch { return null; }
    }

    #swReady() {
        return Promise.race([
            navigator.serviceWorker.ready,
            new Promise((_, r) => setTimeout(() => r(new Error('SW timeout')), 5000)),
        ]);
    }

    #render(active, blocked) {
        if (!this.hasBtnTarget) return;
        const btn = this.btnTarget;

        if (blocked) {
            btn.textContent = 'Bloquées par le navigateur';
            btn.disabled    = true;
            btn.style.opacity = '0.5';
            if (this.hasStatusTarget) {
                this.statusTarget.textContent = 'Va dans Réglages > Safari > Notifications pour autoriser.';
            }
            return;
        }

        btn.disabled      = false;
        btn.style.opacity = '1';

        if (active) {
            btn.textContent = 'Désactiver';
            btn.classList.remove('btn-primary');
            btn.classList.add('btn-secondary');
            if (this.hasStatusTarget) this.statusTarget.textContent = 'Activées sur cet appareil ✓';
        } else {
            btn.textContent = 'Activer';
            btn.classList.remove('btn-secondary');
            btn.classList.add('btn-primary');
            if (this.hasStatusTarget) this.statusTarget.textContent = 'Désactivées';
        }
    }

    #setLoading() {
        if (this.hasBtnTarget) {
            this.btnTarget.textContent = '…';
            this.btnTarget.disabled    = true;
        }
    }

    #supported() {
        return 'Notification' in window && 'serviceWorker' in navigator && 'PushManager' in window;
    }

    #b64(s) {
        const pad = '='.repeat((4 - s.length % 4) % 4);
        const raw = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
    }
}
