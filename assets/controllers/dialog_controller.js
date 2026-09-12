import { Controller } from '@hotwired/stimulus';

/* Ouvre et ferme un <dialog> natif : data-dialog-target="dialog" sur l'élément,
   data-action="dialog#open" / "dialog#close" sur les boutons. */
export default class extends Controller {
    static targets = ['dialog'];

    open() {
        this.dialogTarget.showModal();
    }

    close() {
        this.dialogTarget.close();
    }
}
