import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'feedback'];

    #timer = null;

    check() {
        clearTimeout(this.#timer);
        const val = this.inputTarget.value.trim();

        if (val.length < 3) {
            this.feedbackTarget.innerHTML = '';
            return;
        }

        this.#timer = setTimeout(async () => {
            const res = await fetch(`/register/check-username?username=${encodeURIComponent(val)}`);
            const { available } = await res.json();

            if (available === null) {
                this.feedbackTarget.innerHTML = '';
            } else if (available) {
                this.feedbackTarget.innerHTML = `
                    <div class="flex items-center gap-1.5 mt-1.5 text-xs font-medium rounded-lg px-3 py-2"
                         style="background:rgba(74,222,128,0.08);border:1px solid rgba(74,222,128,0.25);color:#4ade80">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                        @${val} est disponible
                    </div>`;
            } else {
                this.feedbackTarget.innerHTML = `
                    <div class="flex items-center gap-1.5 mt-1.5 text-xs font-medium rounded-lg px-3 py-2"
                         style="background:rgba(232,155,142,0.1);border:1px solid rgba(232,155,142,0.25);color:#E89B8E">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
                        @${val} est déjà pris
                    </div>`;
            }
        }, 350);
    }
}
