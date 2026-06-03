import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        setTimeout(() => this.dismiss(), 4000);
    }

    dismiss() {
        this.element.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
        this.element.style.opacity = '0';
        this.element.style.transform = 'translateY(-8px)';
        setTimeout(() => this.element.remove(), 300);
    }
}
