import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { publicKey: String };

    connect() {
        if (!('Notification' in window) || !('serviceWorker' in navigator) || !('PushManager' in window)) {
            this.element.style.display = 'none';
            return;
        }
        if (Notification.permission === 'denied') {
            this.element.style.display = 'none';
            return;
        }
        if (Notification.permission === 'granted') {
            navigator.serviceWorker.ready
                .then(reg => reg.pushManager.getSubscription())
                .then(sub => { if (sub) this.element.style.display = 'none'; })
                .catch(() => {});
        }
    }

    async enable() {
        const permission = await Notification.requestPermission();

        if (permission !== 'granted') {
            if (permission === 'denied') this.element.style.display = 'none';
            return;
        }

        try {
            const reg = await navigator.serviceWorker.ready;
            const sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: this._b64(this.publicKeyValue),
            });
            await fetch('/push/subscribe', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(sub.toJSON()),
            });
            this.element.style.display = 'none';
        } catch (e) {
            console.warn('Push:', e);
        }
    }

    _b64(s) {
        const pad = '='.repeat((4 - s.length % 4) % 4);
        const raw = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
    }
}
