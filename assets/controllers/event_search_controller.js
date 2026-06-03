import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'results'];

    async search() {
        const q = this.inputTarget.value.trim();
        if (q.length < 2) {
            this.resultsTarget.classList.add('hidden');
            return;
        }

        const res = await fetch(`/event/search?q=${encodeURIComponent(q)}`);
        const events = await res.json();

        if (events.length === 0) {
            this.resultsTarget.innerHTML = `<p style="font-size:12px;color:var(--fg-4);padding:6px 8px">Aucun événement trouvé</p>`;
            this.resultsTarget.classList.remove('hidden');
            return;
        }

        this.resultsTarget.innerHTML = events.map(e => `
            <button type="button"
                    style="background:var(--bg-2);border:1px solid var(--border-subtle);border-radius:10px;width:100%;text-align:left;padding:10px 12px;cursor:pointer;display:block;margin-bottom:4px;font-family:inherit"
                    data-action="click->event-search#selectEvent"
                    data-id="${e.id}"
                    data-name="${(e.name || 'Événement').replace(/"/g, '&quot;')}">
                <div style="display:flex;align-items:center;gap:8px">
                    <span style="font-size:14px;font-weight:500;color:var(--fg-1)">${e.name || 'Événement'}</span>
                    <span style="font-size:11px;background:var(--bg-3);color:var(--fg-3);padding:2px 7px;border-radius:999px;text-transform:capitalize">${e.type}</span>
                </div>
                <div style="font-size:12px;color:var(--fg-4);margin-top:2px">
                    ${e.date}${e.venue ? ' · ' + e.venue : ''}${e.city ? ', ' + e.city : ''}
                    <span style="color:var(--positive);margin-left:4px">👥 ${e.participants}</span>
                </div>
            </button>
        `).join('');
        this.resultsTarget.classList.remove('hidden');
    }

    selectEvent(e) {
        const btn = e.currentTarget;
        document.getElementById('input-existing-event-id').value = btn.dataset.id;
        this.inputTarget.value = btn.dataset.name;
        this.resultsTarget.classList.add('hidden');
        this.inputTarget.style.outline = '2px solid var(--positive)';
        this.inputTarget.style.outlineOffset = '-2px';
        this.inputTarget.readOnly = true;

        const newEventArea = document.getElementById('new-event-area');
        const separator = document.getElementById('new-event-separator');
        const confirmArea = document.getElementById('existing-event-confirm');
        if (newEventArea) newEventArea.classList.add('hidden');
        if (separator) separator.classList.add('hidden');
        if (confirmArea) confirmArea.classList.remove('hidden');
    }

    clearSelection() {
        document.getElementById('input-existing-event-id').value = '';
        this.inputTarget.value = '';
        this.inputTarget.readOnly = false;
        this.inputTarget.style.outline = '';

        const newEventArea = document.getElementById('new-event-area');
        const separator = document.getElementById('new-event-separator');
        const confirmArea = document.getElementById('existing-event-confirm');
        if (newEventArea) newEventArea.classList.remove('hidden');
        if (separator) separator.classList.remove('hidden');
        if (confirmArea) confirmArea.classList.add('hidden');
    }
}
