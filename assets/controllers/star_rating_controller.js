import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.stars = Array.from(this.element.querySelectorAll('.rating-star'));
        this.labels = Array.from(this.element.querySelectorAll('label'));

        this.labels.forEach((label, i) => {
            label.addEventListener('mouseenter', () => this.highlight(i + 1));
        });

        this.element.addEventListener('mouseleave', () => this.updateFromSelected());

        this.element.querySelectorAll('input[type="radio"]').forEach(input => {
            input.addEventListener('change', () => this.updateFromSelected());
        });
    }

    updateFromSelected() {
        const checked = this.element.querySelector('input:checked');
        this.highlight(checked ? parseInt(checked.value) : 0);
    }

    highlight(upTo) {
        this.stars.forEach((star, i) => {
            star.style.color = i < upTo ? 'var(--positive)' : 'var(--bg-3)';
        });
    }
}
