import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'results'];
    static values = { url: String };

    async search() {
        const q = this.inputTarget.value.trim();
        if (q.length < 2) {
            this.resultsTarget.classList.add('hidden');
            return;
        }

        const res = await fetch(`${this.urlValue}?q=${encodeURIComponent(q)}`);
        const suggestions = await res.json();

        if (suggestions.length === 0) {
            this.resultsTarget.classList.add('hidden');
            return;
        }

        this.resultsTarget.innerHTML = suggestions.map(s => `
            <button type="button"
                    style="background:var(--bg-2);border:1px solid var(--border-subtle);border-radius:8px;width:100%;text-align:left;padding:7px 12px;cursor:pointer;display:block;margin-bottom:3px;font-size:13px;font-family:inherit;color:var(--fg-2)"
                    data-action="click->autocomplete#select"
                    data-value="${s.replace(/"/g, '&quot;')}">
                ${s}
            </button>
        `).join('');
        this.resultsTarget.classList.remove('hidden');
    }

    select(e) {
        this.inputTarget.value = e.currentTarget.dataset.value;
        this.resultsTarget.classList.add('hidden');
    }
}
