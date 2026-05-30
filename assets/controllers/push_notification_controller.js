import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { publicKey: String };

    connect() {
        if (!this.#supported() || Notification.permission === 'denied') {
            this.element.style.display = 'none';
            return;
        }
        // granted → hide immediately; no need to wait for SW subscription check
        if (Notification.permission === 'granted') {
            this.element.style.display = 'none';
            return;
        }
        // default → show opt-in card
    }

    async enable() {
        const btn = this.element.querySelector('button');
        if (btn) { btn.disabled = true; btn.textContent = '…'; }

        const perm = await Notification.requestPermission();

        if (perm === 'denied' || perm !== 'granted') {
            this.element.style.display = 'none';
            return;
        }

        try {
            await this.#subscribe();
        } catch (e) {
            console.warn('Push subscribe error:', e);
        }
        this.element.style.display = 'none';
    }

    // ── Private ─────────────────────────────────────────────────────────────

    async #subscribe() {
        const reg = await navigator.serviceWorker.ready;
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

    #supported() {
        return 'Notification' in window && 'serviceWorker' in navigator && 'PushManager' in window;
    }

    #b64(s) {
        const pad = '='.repeat((4 - s.length % 4) % 4);
        const raw = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
    }
}
