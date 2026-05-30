import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['btn'];
    static values = { publicKey: String };

    connect() {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            this.element.style.display = 'none';
            return;
        }

        navigator.serviceWorker.ready.then(reg =>
            reg.pushManager.getSubscription()
        ).then(sub => {
            if (sub || Notification.permission === 'denied') {
                this.element.style.display = 'none';
            }
        }).catch(() => {});
    }

    async enable() {
        const btn = this.element.querySelector('button');
        if (btn) { btn.disabled = true; btn.textContent = '…'; }

        try {
            const permission = await Notification.requestPermission();

            if (permission === 'denied') {
                this.element.style.display = 'none';
                return;
            }
            if (permission !== 'granted') {
                if (btn) { btn.disabled = false; btn.textContent = 'Activer'; }
                return;
            }

            // Déléguer à la fonction globale (définie dans base.html.twig)
            if (typeof window._iwtSubscribePush === 'function') {
                await window._iwtSubscribePush();
            }

            this.element.style.display = 'none';
        } catch (e) {
            console.warn('Push subscription failed:', e);
            if (btn) { btn.disabled = false; btn.textContent = 'Activer'; }
        }
    }
}
