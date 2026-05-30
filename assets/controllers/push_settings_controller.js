import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['btn', 'status'];
    static values  = { publicKey: String };

    connect() {
        if (!this.#supported()) { this.element.style.display = 'none'; return; }
        this.#refresh();
    }

    async toggle() {
        this.#setLoading();
        try {
            if (Notification.permission === 'denied') {
                this.#render(false, true);
                return;
            }
            const sub = await this.#getSub();
            if (sub) {
                await this.#unsubscribe(sub);
                this.#render(false, false);
            } else {
                await this.#requestAndSubscribe();
            }
        } catch (e) {
            console.warn('Push toggle error:', e);
            this.#refresh();
        }
    }

    // ── Private ─────────────────────────────────────────────────────────────

    #refresh() {
        const perm = Notification.permission;
        if (perm === 'denied') {
            this.#render(false, true);
            return;
        }
        if (perm === 'default') {
            this.#render(false, false);
            return;
        }
        // granted — show active immediately, then correct if no subscription exists
        this.#render(true, false);
        this.#getSub()
            .then(sub => { if (!sub) this.#render(false, false); })
            .catch(() => {});
    }

    async #requestAndSubscribe() {
        const perm = await Notification.requestPermission();
        if (perm === 'denied') { this.#render(false, true); return; }
        if (perm !== 'granted') { this.#render(false, false); return; }
        try {
            await this.#subscribe();
            this.#render(true, false);
        } catch (e) {
            console.warn('Push subscribe error:', e);
            this.#render(false, false);
        }
    }

    async #subscribe() {
        const reg = await navigator.serviceWorker.ready;
        // Unsubscribe first — required when VAPID key has changed
        const old = await reg.pushManager.getSubscription();
        if (old) await old.unsubscribe();
        const sub = await reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: this.#b64(this.publicKeyValue),
        });
        const resp = await fetch('/push/subscribe', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(sub.toJSON()),
        });
        if (!resp.ok) throw new Error(`Subscribe failed: ${resp.status}`);
        localStorage.setItem('iwtVapidKey', this.publicKeyValue);
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
        const reg = await navigator.serviceWorker.ready;
        return reg.pushManager.getSubscription();
    }

    #render(active, blocked) {
        if (this.hasStatusTarget) {
            const s = this.statusTarget;
            if (blocked) {
                s.textContent = 'Bloquées — autorise dans Réglages > Safari > Notifications.';
                s.style.color = 'var(--negative)';
            } else if (active) {
                s.textContent = 'Activées sur cet appareil ✓';
                s.style.color = 'var(--positive)';
            } else {
                s.textContent = 'Désactivées';
                s.style.color = 'var(--fg-4)';
            }
        }
        if (!this.hasBtnTarget) return;
        const btn = this.btnTarget;
        btn.disabled    = blocked;
        btn.style.opacity = blocked ? '0.5' : '1';
        if (blocked) {
            btn.textContent = 'Bloquées par le navigateur';
            return;
        }
        if (active) {
            btn.textContent = 'Désactiver';
            btn.classList.remove('btn-primary');
            btn.classList.add('btn-secondary');
        } else {
            btn.textContent = 'Activer';
            btn.classList.remove('btn-secondary');
            btn.classList.add('btn-primary');
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
