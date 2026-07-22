import { Controller } from '@hotwired/stimulus';
import { burstConfetti } from '../confetti.js';

/**
 * Fête un moment de récompense : salve de confettis + « pop » des éléments
 * concernés. Deux usages, selon les valeurs posées sur l'élément :
 *
 *   - Salve immédiate (data-celebrate-auto-value="true") : une salve dès que la
 *     vue apparaît. Pour un écran de fin déjà « gagné » côté serveur.
 *
 *   - Succès (data-celebrate-keys-value="[...]") : compare les clés décrochées à
 *     ce qu'on a déjà montré (localStorage) et ne fête QUE les nouveaux — pop des
 *     pastilles neuves puis salve. Rien à stocker en base : le « déjà vu » vit
 *     dans le navigateur, ce qui suffit à ne célébrer un badge qu'une fois.
 */
export default class extends Controller {
    static values = {
        keys: Array,
        storageKey: { type: String, default: 'iwt-seen-badges' },
        auto: Boolean,
    };

    connect() {
        if (this.autoValue) {
            // Léger délai : on laisse la vue se poser avant la salve.
            this.timer = setTimeout(() => burstConfetti(), 180);

            return;
        }
        if (this.hasKeysValue && this.keysValue.length) {
            this.celebrateNew();
        }
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    /** Déclenchement manuel, depuis un data-action. */
    burst() {
        burstConfetti();
    }

    celebrateNew() {
        let seen = [];
        try {
            seen = JSON.parse(localStorage.getItem(this.storageKeyValue) || '[]');
        } catch { seen = []; }

        const seenSet = new Set(seen);
        const fresh = this.keysValue.filter((k) => !seenSet.has(k));

        // On mémorise l'état courant quoi qu'il arrive : le passé ne se refête pas.
        try {
            localStorage.setItem(this.storageKeyValue, JSON.stringify(this.keysValue));
        } catch { /* stockage indisponible : pas de fête, mais rien de cassé */ }

        // Première visite (rien de mémorisé) : ne pas noyer l'écran de confettis
        // pour un historique déjà là — on se contente d'enregistrer le point de départ.
        if (!seen.length || !fresh.length) { return; }

        const nodes = fresh
            .map((k) => this.element.querySelector(`[data-badge-key="${k}"]`))
            .filter(Boolean);

        nodes.forEach((el, i) => {
            el.style.setProperty('--pop-delay', `${i * 90}ms`);
            el.classList.add('iwt-earned-pop');
        });

        // Salve depuis la première pastille neuve (sinon depuis le haut de l'écran).
        const box = nodes[0]?.getBoundingClientRect();
        const origin = box
            ? { x: (box.left + box.width / 2) / window.innerWidth, y: (box.top + box.height / 2) / window.innerHeight }
            : undefined;

        this.timer = setTimeout(
            () => burstConfetti({ origin, count: Math.min(70 + fresh.length * 20, 160) }),
            220,
        );
    }
}
