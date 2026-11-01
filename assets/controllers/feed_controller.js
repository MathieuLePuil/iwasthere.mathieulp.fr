import { Controller } from '@hotwired/stimulus';

/** Distance avant le bas de l'écran à laquelle on précharge la suite */
const MARGIN = 600;

/**
 * Scroll infini du feed : quand le sentinel approche du viewport,
 * charge la page suivante (fragment HTML de /feed/items) et l'ajoute
 * à la liste. `seen` transmet la limite « déjà vu » de la visite
 * précédente pour que le serveur place le séparateur au bon endroit.
 */
export default class extends Controller {
    static targets = ['list', 'sentinel'];
    static values = { url: String, page: Number, seen: Number };

    connect() {
        this._loading = false;
        this._finished = false;
        this._observer = new IntersectionObserver(
            (entries) => { if (entries.some((e) => e.isIntersecting)) this._loadMore(); },
            { rootMargin: `${MARGIN}px 0px` },
        );
        this._observer.observe(this.sentinelTarget);
    }

    disconnect() {
        this._observer?.disconnect();
    }

    async _loadMore() {
        if (this._loading || this._finished) return;
        this._loading = true;

        let loaded = false;
        try {
            const url = new URL(this.urlValue, window.location.origin);
            url.searchParams.set('page', this.pageValue);
            url.searchParams.set('seen', this.seenValue);
            const res = await fetch(url, { credentials: 'same-origin' });
            if (res.ok) {
                const html = await res.text();
                if (html.trim()) this.listTarget.insertAdjacentHTML('beforeend', html);
                this.pageValue += 1;
                if (res.headers.get('X-Feed-Has-More') !== '1') this._finish();
                loaded = true;
            }
        } catch (_) { /* réessaiera à la prochaine intersection */ }

        this._loading = false;

        // L'observer ne notifie que les changements d'intersection : si la page
        // ajoutée est courte, le sentinel peut rester visible sans redéclencher.
        // On enchaîne donc tant qu'il l'est. En cas d'échec on s'arrête, la
        // prochaine intersection réessaiera (pas de boucle sur le serveur).
        if (loaded && !this._finished && this._sentinelVisible()) {
            this._loadMore();
        }
    }

    _sentinelVisible() {
        const { top } = this.sentinelTarget.getBoundingClientRect();
        return top <= window.innerHeight + MARGIN;
    }

    _finish() {
        this._finished = true;
        this._observer.disconnect();
        this.sentinelTarget.style.display = 'none';
    }
}
