import { Controller } from '@hotwired/stimulus';

/*
 * Recherche au fil de la saisie (alertes billetterie) : le formulaire GET est
 * rejoué en fetch avec `fragment=1`, et seule la liste de résultats est
 * remplacée — le champ garde le focus, l'URL suit (replaceState) pour qu'un
 * rechargement ou un « Suivre » retombe sur la même recherche.
 *
 * Sans JavaScript, le formulaire s'envoie normalement (bouton dans <noscript>).
 *
 * <form data-controller="live-search" data-live-search-results-value="#tm-results">
 *   <input name="q" data-action="input->live-search#search">
 *   <a data-action="click->live-search#pick" data-param="segment" data-value="Sports">
 */
export default class extends Controller {
    static values = { results: String, delay: { type: Number, default: 250 } };

    connect() {
        this.results = document.querySelector(this.resultsValue);
        this.controller = null;
    }

    disconnect() {
        clearTimeout(this.timer);
        this.controller?.abort();
    }

    /** Champ texte, ville, dates : on attend une courte pause avant d'interroger. */
    search() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.load(this.params()), this.delayValue);
    }

    /** Une puce (segment, ouverture à venir / en vente) : valeur posée dans le champ caché, puis recherche. */
    pick(event) {
        event.preventDefault();
        const { param, value } = event.currentTarget.dataset;
        const hidden = this.element.querySelector(`input[type="hidden"][name="${param}"]`);
        if (hidden) hidden.value = value;

        for (const pill of event.currentTarget.parentElement.querySelectorAll('.iwt-pill')) {
            pill.classList.toggle('active', pill === event.currentTarget);
        }
        this.load(this.params());
    }

    /** Pagination dans le fragment : même mécanique, avec le numéro de page du lien. */
    page(event) {
        event.preventDefault();
        const page = new URL(event.currentTarget.href, location.origin).searchParams.get('page');
        const params = this.params();
        if (page) params.set('page', page);
        this.load(params);
        this.results?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    params() {
        const params = new URLSearchParams(new FormData(this.element));
        for (const [key, value] of [...params]) {
            if (value === '') params.delete(key);
        }
        return params;
    }

    async load(params) {
        if (!this.results) return;
        this.controller?.abort();
        this.controller = new AbortController();

        const query = params.toString();
        history.replaceState(null, '', this.element.action + (query ? '?' + query : ''));

        params.set('fragment', '1');
        this.results.style.opacity = '0.5';
        try {
            const res = await fetch(`${this.element.action}?${params}`, {
                headers: { Accept: 'text/html' },
                signal: this.controller.signal,
            });
            if (res.ok) this.results.innerHTML = await res.text();
        } catch (e) {
            if (e.name !== 'AbortError') console.error(e);
        } finally {
            this.results.style.opacity = '';
        }
    }
}
