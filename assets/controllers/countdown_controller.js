import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['days', 'hours', 'minutes', 'seconds'];
    static values = { date: String };

    connect() {
        this.tick();
        this.timer = setInterval(() => this.tick(), 1000);
    }

    disconnect() {
        clearInterval(this.timer);
    }

    tick() {
        const diff = new Date(this.dateValue).getTime() - Date.now();

        if (diff <= 0) {
            this.daysTarget.textContent = '0';
            this.hoursTarget.textContent = '00';
            this.minutesTarget.textContent = '00';
            this.secondsTarget.textContent = '00';
            clearInterval(this.timer);
            return;
        }

        this.daysTarget.textContent = Math.floor(diff / 86400000);
        this.hoursTarget.textContent = String(Math.floor((diff % 86400000) / 3600000)).padStart(2, '0');
        this.minutesTarget.textContent = String(Math.floor((diff % 3600000) / 60000)).padStart(2, '0');
        this.secondsTarget.textContent = String(Math.floor((diff % 60000) / 1000)).padStart(2, '0');
    }
}
