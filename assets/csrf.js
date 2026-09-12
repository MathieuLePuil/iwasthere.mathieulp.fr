/*
 * Jeton CSRF sur toute requête qui écrit.
 *
 * Le serveur (CsrfProtectionListener) refuse tout POST sans jeton valide, dans
 * le champ `_token` ou l'en-tête `X-CSRF-Token`. Plutôt que d'ajouter un champ
 * caché dans chacun des ~40 formulaires, on l'injecte ici au moment de l'envoi :
 * la balise <meta name="csrf-token"> du layout porte le jeton de la session.
 *
 * Le listener est en phase de capture sur `document` pour passer avant tout
 * autre gestionnaire. `form.submit()` ne déclenche pas l'événement — les
 * envois automatiques passent donc par requestSubmit() (contrôleur auto-submit).
 */
export function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

/** En-têtes à joindre aux fetch() qui écrivent. */
export function csrfHeaders(extra = {}) {
    return { 'X-CSRF-Token': csrfToken(), ...extra };
}

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
    if (form.querySelector('input[name="_token"]')) return;

    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = '_token';
    input.value = csrfToken();
    form.appendChild(input);
}, true);
