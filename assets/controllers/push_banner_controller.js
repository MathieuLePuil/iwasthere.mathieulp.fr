import { Controller } from '@hotwired/stimulus';

/* La bannière « active les notifications » ne s'affiche que si le navigateur
   ne les a pas déjà accordées. */
export default class extends Controller {
    connect() {
        if (!('Notification' in window) || Notification.permission !== 'granted') {
            this.element.hidden = false;
        }
    }
}
