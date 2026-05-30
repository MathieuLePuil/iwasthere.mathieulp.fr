import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['btn'];
    static values = { publicKey: String };

    connect() {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            this.element.style.display = 'none';
            return;
        }

        navigator.serviceWorker.ready.then(reg => {
            reg.pushManager.getSubscription().then(sub => {
                if (sub) {
                    this.element.style.display = 'none';
                }
            });
        });
    }

    async enable() {
        const reg = await navigator.serviceWorker.ready;
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') return;

        try {
            const sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: this._urlBase64ToUint8Array(this.publicKeyValue),
            });

            await fetch('/push/subscribe', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(sub.toJSON()),
            });

            this.element.style.display = 'none';
        } catch (e) {
            console.warn('Push subscription failed:', e);
        }
    }

    _urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const rawData = window.atob(base64);
        return Uint8Array.from([...rawData].map(c => c.charCodeAt(0)));
    }
}
