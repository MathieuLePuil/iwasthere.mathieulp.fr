import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'results', 'venueId'];

    async search() {
        const q = this.inputTarget.value.trim();
        if (q.length < 2) {
            this.resultsTarget.classList.add('hidden');
            return;
        }

        const res = await fetch(`/event/venues/search?q=${encodeURIComponent(q)}`);
        const venues = await res.json();

        if (venues.length === 0) {
            this.resultsTarget.classList.add('hidden');
            return;
        }

        this.resultsTarget.innerHTML = venues.map(v => `
            <button type="button"
                    style="background:var(--bg-2);border:1px solid var(--border-subtle);border-radius:10px;width:100%;text-align:left;padding:8px 12px;cursor:pointer;display:block;margin-bottom:4px;font-size:14px;font-family:inherit"
                    data-id="${v.id}"
                    data-name="${v.name.replace(/"/g, '&quot;')}"
                    data-action="click->venue-search#selectVenue">
                <span style="font-weight:500;color:var(--fg-1)">${v.name}</span>
            </button>
        `).join('');
        this.resultsTarget.classList.remove('hidden');
    }

    selectVenue(e) {
        const btn = e.currentTarget;
        this.venueIdTarget.value = btn.dataset.id;
        this.inputTarget.value = btn.dataset.name;
        this.inputTarget.removeAttribute('name'); // prevent venue_name from being submitted
        this.resultsTarget.classList.add('hidden');
    }
}
