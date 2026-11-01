import { Controller } from '@hotwired/stimulus';

/*
 * Envoie le formulaire dès qu'un champ change (choix d'une photo, d'un filtre).
 * requestSubmit() plutôt que submit() : lui seul déclenche l'événement `submit`,
 * dont dépend l'injection du jeton CSRF (voir csrf.js).
 */
export default class extends Controller {
    submit() {
        this.element.requestSubmit();
    }
}
