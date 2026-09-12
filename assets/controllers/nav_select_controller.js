import { Controller } from '@hotwired/stimulus';

/* Un <select> dont chaque option est une URL : choisir, c'est y aller. */
export default class extends Controller {
    go(event) {
        window.location.assign(event.target.value);
    }
}
