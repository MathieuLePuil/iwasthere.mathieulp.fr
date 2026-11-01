import { Controller } from '@hotwired/stimulus';

/* Une image qui ne charge pas (avatar effacé) laisse place à l'élément qui la
   suit — les initiales. data-action="error->img-fallback#swap" sur l'<img>. */
export default class extends Controller {
    swap(event) {
        const img = event.target;
        img.hidden = true;
        const next = img.nextElementSibling;
        if (next) next.hidden = false;
    }
}
