import { Controller } from '@hotwired/stimulus';

/**
 * Profile-page opt-in card.
 * Hides itself when push is already enabled or unsupported/denied.
 */
export default class extends Controller {
    static values = { publicKey: String };

    async connect() {
        if (!this.#supported() || Notification.permission === 'denied') {
            this.element.style.display = 'none';
            return;
        }
        if (Notification.permission === 'granted') {
            const sub = await this.#getSub();
            if (sub) this.element.style.display = 'none';
        }
    }

    async enable() {
        const btn = this.element.querySelector('button');
        if (btn) { btn.disabled = true; btn.textContent = '…'; }

        const perm = await Notification.requestPermission();

        if (perm === 'denied') { this.element.style.display = 'none'; return; }
        if (perm !== 'granted') {
            if (btn) { btn.disabled = false; btn.textContent = 'Activer'; }
            return;
        }

        try {
            await this.#subscribe();
            this.element.style.display = 'none';
        } catch (e) {
            console.warn('Push subscribe error:', e);
            if (btn) { btn.disabled = false; btn.textContent = 'Activer'; }
        }
    }

    // ── Private ─────────────────────────────────────────────────────────────

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

    #supported() {
        return 'Notification' in window && 'serviceWorker' in navigator && 'PushManager' in window;
    }

    #b64(s) {
        const pad = '='.repeat((4 - s.length % 4) % 4);
        const raw = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
    }
}
