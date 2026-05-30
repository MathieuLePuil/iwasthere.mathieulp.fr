import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'feedback'];
    static values  = { endpoint: { type: String, default: '/register/check-username' } };

    #timer = null;

    check() {
        clearTimeout(this.#timer);
        const val = this.inputTarget.value.trim().toLowerCase();

        if (val.length < 3) {
            this.feedbackTarget.innerHTML = '';
            return;
        }

        this.#timer = setTimeout(async () => {
            const res = await fetch(`${this.endpointValue}?username=${encodeURIComponent(val)}`);
            const { available } = await res.json();

            if (available === null) {
                this.feedbackTarget.innerHTML = '';
            } else if (available === 'current') {
                this.feedbackTarget.innerHTML = this.#badge('info', `@${val} — ton pseudo actuel`);
            } else if (available) {
                this.feedbackTarget.innerHTML = this.#badge('ok', `@${val} est disponible`);
            } else {
                this.feedbackTarget.innerHTML = this.#badge('err', `@${val} est déjà pris`);
            }
        }, 350);
    }

    #badge(type, text) {
        const s = {
            ok:   'background:rgba(74,222,128,0.08);border:1px solid rgba(74,222,128,0.25);color:#4ade80',
            err:  'background:rgba(232,155,142,0.1);border:1px solid rgba(232,155,142,0.25);color:#E89B8E',
            info: 'background:rgba(96,165,250,0.08);border:1px solid rgba(96,165,250,0.25);color:#60A5FA',
        };
        const icon = {
            ok:   '<path d="M20 6L9 17l-5-5"/>',
            err:  '<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>',
            info: '<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>',
        };
        return `<div class="flex items-center gap-1.5 mt-1.5 text-xs font-medium rounded-lg px-3 py-2" style="${s[type]}">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">${icon[type]}</svg>
            ${text}
        </div>`;
    }
}
