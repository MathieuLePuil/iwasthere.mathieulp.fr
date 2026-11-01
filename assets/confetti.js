/**
 * Une salve de confettis, rendue sur un canvas plein écran éphémère puis retiré.
 * Pensée pour les moments de récompense (succès décroché, fin du Rewind).
 *
 * Canvas plutôt que des <span> animés : une centaine de brins qui tombent en
 * même temps, c'est du repaint continu — un seul canvas coûte un contexte, pas
 * cent nœuds. La physique (gravité + frottement) tient en quelques lignes et
 * donne une chute plus vivante qu'une keyframe linéaire.
 *
 * Respecte prefers-reduced-motion : dans ce cas la fonction ne fait rien.
 *
 * @param {object}  [options]
 * @param {number}  [options.count=90]              nombre de brins
 * @param {{x:number,y:number}} [options.origin]    point d'émission, en fractions de la fenêtre
 * @param {number}  [options.power=1]               multiplicateur de vitesse initiale
 * @param {number}  [options.spread=1]              largeur de l'éventail
 */
export function burstConfetti(options = {}) {
    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) { return; }

    const {
        count = 90,
        origin = { x: 0.5, y: 0.26 },
        power = 1,
        spread = 1,
    } = options;

    // Palette de l'app : mêmes teintes que les accents, pour rester chez nous.
    const colors = ['#B060FF', '#C890FF', '#7EE8A2', '#F4D04A', '#60A5FA', '#E89B8E'];

    const canvas = document.createElement('canvas');
    canvas.setAttribute('aria-hidden', 'true');
    Object.assign(canvas.style, {
        position: 'fixed', inset: '0', width: '100%', height: '100%',
        pointerEvents: 'none', zIndex: '2147483000',
    });
    document.body.appendChild(canvas);

    const ctx = canvas.getContext('2d');
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    const W = canvas.width = window.innerWidth * dpr;
    const H = canvas.height = window.innerHeight * dpr;

    const ox = origin.x * W;
    const oy = origin.y * H;
    const gravity = 0.35 * dpr;
    const drag = 0.992;

    const parts = Array.from({ length: count }, () => {
        // Éventail dirigé vers le haut : les brins jaillissent puis retombent.
        const angle = -Math.PI / 2 + (Math.random() - 0.5) * Math.PI * 0.9 * spread;
        const speed = (7 + Math.random() * 9) * power * dpr;

        return {
            x: ox + (Math.random() - 0.5) * 40 * dpr,
            y: oy,
            vx: Math.cos(angle) * speed,
            vy: Math.sin(angle) * speed,
            rot: Math.random() * Math.PI,
            vr: (Math.random() - 0.5) * 0.3,
            w: (6 + Math.random() * 6) * dpr,
            h: (8 + Math.random() * 8) * dpr,
            color: colors[(Math.random() * colors.length) | 0],
            round: Math.random() < 0.35,
        };
    });

    const start = performance.now();
    const DURATION = 2600;

    function frame(now) {
        const t = now - start;
        ctx.clearRect(0, 0, W, H);
        let alive = false;

        // Fondu final sur les 1,2 dernières secondes, pour que rien ne disparaisse net.
        const fade = Math.max(0, 1 - Math.max(0, t - 1400) / 1200);

        for (const p of parts) {
            p.vy += gravity;
            p.vx *= drag;
            p.vy *= drag;
            p.x += p.vx;
            p.y += p.vy;
            p.rot += p.vr;

            if (fade <= 0 || p.y > H + 40) { continue; }
            alive = true;

            ctx.save();
            ctx.translate(p.x, p.y);
            ctx.rotate(p.rot);
            ctx.globalAlpha = fade;
            ctx.fillStyle = p.color;
            if (p.round) {
                ctx.beginPath();
                ctx.arc(0, 0, p.w / 2, 0, Math.PI * 2);
                ctx.fill();
            } else {
                ctx.fillRect(-p.w / 2, -p.h / 2, p.w, p.h);
            }
            ctx.restore();
        }

        if (alive && t < DURATION) {
            requestAnimationFrame(frame);
        } else {
            canvas.remove();
        }
    }

    requestAnimationFrame(frame);
}
