import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['badge'];
    static values = { count: Number };

    connect() {
        this._update(this.countValue);
        this._startPolling();
        this._onVisibility = () => { if (!document.hidden) this._fetch(); };
        document.addEventListener('visibilitychange', this._onVisibility);
    }

    disconnect() {
        this._stopPolling();
        document.removeEventListener('visibilitychange', this._onVisibility);
    }

    // Toutes les 60 s, et seulement quand l'onglet est visible : un onglet oublié
    // en arrière-plan n'a pas à interroger le serveur toute la journée.
    _startPolling() {
        this._timer = setInterval(() => { if (!document.hidden) this._fetch(); }, 60_000);
    }

    _stopPolling() {
        clearInterval(this._timer);
    }

    async _fetch() {
        try {
            const res = await fetch('/notifications/count', { credentials: 'same-origin' });
            if (!res.ok) return;
            const { count } = await res.json();
            this._update(count);
        } catch (_) { /* silently ignore network errors */ }
    }

    _update(count) {
        const badge = this.badgeTarget;
        if (count > 0) {
            badge.textContent = count > 9 ? '9+' : String(count);
            badge.style.display = '';
        } else {
            badge.style.display = 'none';
        }
    }
}
