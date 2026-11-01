import { Controller } from '@hotwired/stimulus';

/**
 * Shows a spinner on the form's submit button(s) while the form is being
 * submitted, so the user gets immediate feedback that the click was taken
 * into account. Attach with data-controller="form-loader" on a <form>.
 */
export default class extends Controller {
    connect() {
        this.onSubmit = () => this.start();
        this.onPageshow = (event) => { if (event.persisted) this.stop(); };
        this.element.addEventListener('submit', this.onSubmit);
        window.addEventListener('pageshow', this.onPageshow);
    }

    disconnect() {
        this.element.removeEventListener('submit', this.onSubmit);
        window.removeEventListener('pageshow', this.onPageshow);
        this.stop();
    }

    start() {
        this.buttons().forEach(btn => {
            btn.classList.add('btn-loading');
            btn.setAttribute('aria-busy', 'true');
        });
        // Disable after the current tick so the submission itself is not blocked
        setTimeout(() => this.buttons().forEach(btn => { btn.disabled = true; }), 0);
    }

    stop() {
        this.buttons().forEach(btn => {
            btn.classList.remove('btn-loading');
            btn.removeAttribute('aria-busy');
            btn.disabled = false;
        });
    }

    buttons() {
        return [...this.element.querySelectorAll('button[type="submit"], input[type="submit"]')];
    }
}
