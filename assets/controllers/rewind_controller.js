import { Controller } from '@hotwired/stimulus';

/**
 * Le diaporama du Rewind, façon story : barres de progression, avance auto,
 * appui long pour mettre en pause, glissement pour naviguer.
 *
 * Le partage redessine la diapo courante sur un canvas 1080×1920 — le format
 * story. C'est un second rendu, volontairement : à l'écran la diapo doit
 * s'adapter à toutes les tailles, en story elle a un cadre fixe. Les deux lisent
 * la même structure de données (RewindService), donc ils racontent la même chose.
 *
 * Les images d'artistes sont servies depuis notre domaine (/uploads/...), le
 * canvas n'est donc pas « tainted » et toBlob fonctionne.
 */
export default class extends Controller {
    static targets = ['slide', 'bars', 'canvas', 'shareButton', 'shareLabel'];
    static values = {
        slides: Array,
        year: Number,
        name: String,
        home: { type: String, default: '/home' },
        logo: String,
        duration: { type: Number, default: 6000 },
    };

    connect() {
        this.index = 0;
        this.paused = false;
        this.timer = null;

        this.buildBars();
        this.show(0);

        this.onKey = this.handleKey.bind(this);
        window.addEventListener('keydown', this.onKey);

        // Tous les gestes sont lus ici, à la source. Les zones de tap ne peuvent
        // pas être des boutons : leur clic se déclencherait aussi au relâchement
        // d'un swipe, et on avancerait d'une diapo en fermant.
        this.pressTimer = null;
        this.dragging = false;

        this.element.addEventListener('pointerdown', this.onPointerDown = (e) => {
            // Les vrais contrôles (fermer, partager) gardent leur comportement
            if (e.target.closest('a, button')) { return; }

            this.startX = e.clientX;
            this.startY = e.clientY;
            this.startAt = Date.now();
            this.dragging = true;
            this.moved = false;
            // Sans capture, le geste se perd dès que le doigt sort de l'élément
            this.element.setPointerCapture(e.pointerId);
            this.pressTimer = setTimeout(() => { if (!this.moved) { this.pause(); } }, 220);
        });

        this.element.addEventListener('pointermove', this.onPointerMove = (e) => {
            if (!this.dragging) { return; }
            const dx = e.clientX - this.startX;
            const dy = e.clientY - this.startY;
            if (Math.abs(dx) > 8 || Math.abs(dy) > 8) { this.moved = true; }

            // Le glissement vers le bas ferme : on le suit au doigt plutôt que
            // d'attendre un seuil, pour que le geste soit réversible et lisible
            if (dy > 10 && dy > Math.abs(dx)) {
                clearTimeout(this.pressTimer);
                this.pause();
                this.element.style.transition = 'none';
                this.element.style.transform = `translateY(${dy}px) scale(${Math.max(0.88, 1 - dy / 1400)})`;
                this.element.style.opacity = String(Math.max(0.25, 1 - dy / 640));
                this.element.style.borderRadius = `${Math.min(32, dy / 5)}px`;
            }
        });

        this.element.addEventListener('pointerup', this.onPointerUp = (e) => {
            if (!this.dragging) { return; }
            clearTimeout(this.pressTimer);
            this.dragging = false;

            const dx = e.clientX - this.startX;
            const dy = e.clientY - this.startY;

            if (dy > 130 && dy > Math.abs(dx)) {
                this.close();
                return;
            }

            this.settle();
            this.resume();

            if (Math.abs(dx) > 55 && Math.abs(dx) > Math.abs(dy)) {
                dx > 0 ? this.prev() : this.next();
                return;
            }

            // Un tap franc : c'est le côté touché qui décide, comme dans les stories
            if (!this.moved && Date.now() - this.startAt < 400) {
                const rect = this.element.getBoundingClientRect();
                e.clientX - rect.left < rect.width * 0.3 ? this.prev() : this.next();
            }
        });

        this.element.addEventListener('pointercancel', this.onPointerCancel = () => {
            clearTimeout(this.pressTimer);
            this.dragging = false;
            this.settle();
            this.resume();
        });
    }

    /** Ramène la scène en place après un glissement abandonné. */
    settle() {
        this.element.style.transition = 'transform .32s cubic-bezier(.16,1,.3,1), opacity .32s ease, border-radius .32s ease';
        this.element.style.transform = '';
        this.element.style.opacity = '';
        this.element.style.borderRadius = '';
    }

    /** Sort du Rewind en laissant l'animation finir, pour ne pas couper le geste. */
    close() {
        clearTimeout(this.timer);
        this.element.style.transition = 'transform .26s ease-in, opacity .26s ease-in';
        this.element.style.transform = 'translateY(100%)';
        this.element.style.opacity = '0';
        setTimeout(() => window.location.assign(this.homeValue), 200);
    }

    disconnect() {
        clearTimeout(this.timer);
        clearTimeout(this.pressTimer);
        window.removeEventListener('keydown', this.onKey);
        this.element.removeEventListener('pointerdown', this.onPointerDown);
        this.element.removeEventListener('pointermove', this.onPointerMove);
        this.element.removeEventListener('pointerup', this.onPointerUp);
        this.element.removeEventListener('pointercancel', this.onPointerCancel);
    }

    buildBars() {
        this.barsTarget.innerHTML = '';
        this.slideTargets.forEach(() => {
            const bar = document.createElement('div');
            bar.className = 'rw-bar';
            bar.appendChild(document.createElement('i'));
            this.barsTarget.appendChild(bar);
        });
        this.barsTarget.style.setProperty('--rw-duration', `${this.durationValue}ms`);
    }

    show(i) {
        this.index = i;

        this.slideTargets.forEach((el, n) => {
            n === i ? el.setAttribute('data-active', '') : el.removeAttribute('data-active');
        });

        // Recréer la barre courante relance son animation depuis zéro
        [...this.barsTarget.children].forEach((bar, n) => {
            bar.removeAttribute('data-done');
            bar.removeAttribute('data-current');
            if (n < i) { bar.setAttribute('data-done', ''); }
            if (n === i) {
                bar.replaceChild(document.createElement('i'), bar.firstChild);
                bar.setAttribute('data-current', '');
                bar.style.setProperty('--rw-duration', `${this.durationValue}ms`);
            }
        });

        this.schedule();
    }

    schedule() {
        clearTimeout(this.timer);
        if (this.paused || this.index >= this.slideTargets.length - 1) { return; }
        this.timer = setTimeout(() => this.next(), this.durationValue);
    }

    next() {
        if (this.index < this.slideTargets.length - 1) { this.show(this.index + 1); }
    }

    prev() {
        if (this.index > 0) { this.show(this.index - 1); }
    }

    pause() {
        this.paused = true;
        this.element.setAttribute('data-paused', '');
        clearTimeout(this.timer);
    }

    resume() {
        if (!this.paused) { return; }
        this.paused = false;
        this.element.removeAttribute('data-paused');
        this.schedule();
    }

    handleKey(e) {
        if (e.key === 'ArrowRight' || e.key === ' ') { e.preventDefault(); this.next(); }
        if (e.key === 'ArrowLeft') { e.preventDefault(); this.prev(); }
        if (e.key === 'Escape') { this.close(); }
    }

    /** Rend la diapo courante en PNG, puis propose le partage natif ou le téléchargement. */
    async share() {
        // On ne veut pas que le diaporama avance pendant qu'on prépare l'image
        this.pause();
        const slide = this.slidesValue[this.index];
        const label = this.shareLabelTarget;
        const original = label.textContent;
        label.textContent = 'Préparation…';
        this.shareButtonTarget.disabled = true;

        try {
            const blob = await this.render(slide);
            const file = new File([blob], `rewind-${this.yearValue}-${slide.key}.png`, { type: 'image/png' });

            // Sur mobile, le partage natif ouvre directement Instagram & co. ;
            // ailleurs, il n'existe pas et on retombe sur un téléchargement
            if (navigator.canShare?.({ files: [file] })) {
                await navigator.share({ files: [file] });
                label.textContent = original;
            } else {
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = file.name;
                a.click();
                URL.revokeObjectURL(url);
                label.textContent = 'Image enregistrée';
                setTimeout(() => { label.textContent = original; }, 2000);
            }
        } catch (e) {
            // L'utilisateur qui ferme la feuille de partage n'est pas une erreur
            if (e?.name !== 'AbortError') {
                console.error('rewind share:', e);
                label.textContent = 'Échec, réessaie';
                setTimeout(() => { label.textContent = original; }, 2000);
            } else {
                label.textContent = original;
            }
        } finally {
            this.shareButtonTarget.disabled = false;
        }
    }

    // ── Rendu story ────────────────────────────────────────────────────────

    async render(slide) {
        const c = this.canvasTarget;
        const ctx = c.getContext('2d');
        const W = c.width, H = c.height;
        const A = slide.accent;

        // Sans ça, le PNG sortirait en police système : les webfonts ne sont pas
        // forcément prêtes au moment où on dessine
        await this.ensureFonts();

        // Même fond que la diapo à l'écran : noir de l'app + halo d'accent
        const bg = ctx.createLinearGradient(0, 0, 0, H);
        bg.addColorStop(0, '#0F1116');
        bg.addColorStop(1, '#08090C');
        ctx.fillStyle = bg;
        ctx.fillRect(0, 0, W, H);
        this.glow(ctx, W * 0.92, H * 0.1, W * 0.95, A, 0.4);
        this.glow(ctx, W * 0.05, H * 1.0, W * 0.8, A, 0.16);

        const hero = slide.kind === 'hero' && slide.image ? await this.loadImage(slide.image) : null;
        const logo = this.logoValue ? await this.loadImage(this.logoValue) : null;

        // Deux passes : on mesure d'abord la hauteur du bloc pour le centrer.
        // À l'écran la diapo est centrée par flexbox ; sans ça la story serait
        // tassée en haut avec un grand vide dessous.
        const height = this.content(this.measurer(ctx), slide, 0, hero) ;
        const top = Math.max(280, (H - 190 - height) / 2);
        this.content(ctx, slide, top, hero);

        this.footer(ctx, slide, logo, W, H);

        return new Promise((resolve, reject) => {
            c.toBlob((b) => (b ? resolve(b) : reject(new Error('toBlob a échoué'))), 'image/png');
        });
    }

    /**
     * Pose le contenu de la diapo à partir de `top` et retourne sa hauteur.
     * Appelé une fois pour mesurer, une fois pour peindre — le même code dans
     * les deux cas, c'est ce qui garantit que la mesure ne mente pas.
     */
    content(ctx, slide, top, hero) {
        const W = this.canvasTarget.width;
        const pad = 104;
        const maxW = W - pad * 2;
        const A = slide.accent;
        let y = top;

        ctx.textBaseline = 'top';
        ctx.textAlign = 'left';

        // Accroche : pastille + mono espacé, comme à l'écran
        const eyebrow = this.spaced(slide.eyebrow.toUpperCase());
        const eSize = this.fit(ctx, eyebrow, maxW - 40, 700, 30);
        ctx.fillStyle = A;
        ctx.beginPath();
        ctx.arc(pad + 7, y + eSize / 2 + 1, 7, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillText(eyebrow, pad + 30, y);
        y += 112;

        if (hero) {
            const size = 440;
            const cx = W / 2, cy = y + size / 2;
            this.glow(ctx, cx, cy, size * 0.95, A, 0.3);
            ctx.save();
            ctx.beginPath();
            ctx.arc(cx, cy, size / 2, 0, Math.PI * 2);
            ctx.clip();
            this.drawCover(ctx, hero, cx - size / 2, y, size, size);
            ctx.restore();
            ctx.beginPath();
            ctx.arc(cx, cy, size / 2, 0, Math.PI * 2);
            ctx.strokeStyle = A;
            ctx.lineWidth = 5;
            ctx.stroke();
            y += size + 88;
        }

        // Un nombre nu est l'objet de la diapo : dégradé blanc → accent, comme .rw-figure
        if (/^[0-9]+([,.][0-9]+)?$/.test(slide.title) && slide.kind !== 'list') {
            const size = this.fit(ctx, slide.title, maxW, 700, 300);
            const grad = ctx.createLinearGradient(pad, y, pad + maxW * 0.5, y + size);
            grad.addColorStop(0, '#FFFFFF');
            grad.addColorStop(1, A);
            ctx.fillStyle = grad;
            ctx.fillText(slide.title, pad, y);
            // Des chiffres n'occupent que ~0.72 de la boîte em : sans ce retrait,
            // le sous-titre semblerait décroché très loin sous le nombre
            y += size * 0.78 + 40;
        } else {
            const base = slide.title.length > 22 ? 78 : slide.title.length > 12 ? 104 : 132;
            ctx.fillStyle = '#F3F4F6';
            const longest = slide.title.split(' ').reduce((a, b) => (a.length > b.length ? a : b), '');
            const fitted = this.fit(ctx, longest, maxW, 700, base);
            y = this.wrap(ctx, slide.title, pad, y, maxW, fitted * 1.18);
            y += 34;
        }

        if (slide.subtitle) {
            ctx.fillStyle = 'rgba(243,244,246,0.72)';
            ctx.font = '500 42px Geist, sans-serif';
            y = this.wrap(ctx, slide.subtitle, pad, y, maxW, 62);
            y += 26;
        }

        if (slide.items?.length) {
            y += 42;
            y = slide.kind === 'list'
                ? this.drawRows(ctx, slide.items, pad, y, maxW, A)
                : this.drawBars(ctx, slide.items, pad, y, maxW, A);
        }

        // Énumération : plus présente qu'une note, avec son filet d'accent
        if (slide.prose) {
            y += 40;
            const barTop = y;

            if (slide.prose_label) {
                ctx.fillStyle = A;
                const label = this.spaced(slide.prose_label.toUpperCase());
                this.fit(ctx, label, maxW - 32, 700, 26);
                ctx.fillText(label, pad + 28, y);
                y += 52;
            }

            ctx.fillStyle = 'rgba(243,244,246,0.82)';
            ctx.font = '500 34px Geist, sans-serif';
            y = this.wrap(ctx, slide.prose, pad + 28, y, maxW - 28, 54);

            ctx.fillStyle = this.alpha(A, 0.45);
            ctx.fillRect(pad, barTop, 4, y - barTop - 14);
        }

        if (slide.note) {
            y += 28;
            ctx.fillStyle = 'rgba(243,244,246,0.48)';
            ctx.font = '400 34px Geist, sans-serif';
            y = this.wrap(ctx, slide.note, pad, y, maxW, 52);
        }

        return y - top;
    }

    /** Logo + repères : une story circule loin de l'app, elle doit dire d'où elle vient. */
    footer(ctx, slide, logo, W, H) {
        const pad = 104;
        const foot = H - 156;
        let x = pad;

        if (logo) {
            const h = 54;
            const w = h * (logo.width / logo.height);
            ctx.drawImage(logo, pad, foot - 15, w, h);
            x += w + 22;
        }

        ctx.textBaseline = 'top';
        ctx.textAlign = 'left';
        ctx.fillStyle = 'rgba(243,244,246,0.42)';
        const left = this.spaced(`REWIND ${this.yearValue}`);
        this.fit(ctx, left, W - pad * 2 - (x - pad) - 300, 600, 26);
        ctx.fillText(left, x, foot);

        ctx.textAlign = 'right';
        ctx.fillStyle = slide.accent;
        ctx.font = '700 26px Geist, sans-serif';
        ctx.fillText(this.spaced(String(this.nameValue).toUpperCase()), W - pad, foot);
        ctx.textAlign = 'left';
    }

    /**
     * Un contexte qui mesure sans peindre : il délègue la police et measureText
     * au vrai contexte, et avale tout le reste. Permet de faire tourner `content`
     * une première fois pour connaître sa hauteur.
     */
    measurer(real) {
        const noop = () => {};

        return {
            set font(v) { real.font = v; },
            get font() { return real.font; },
            set fillStyle(v) {}, set strokeStyle(v) {}, set lineWidth(v) {},
            set textAlign(v) {}, set textBaseline(v) {},
            get textAlign() { return 'left'; },
            measureText: (t) => real.measureText(t),
            fillText: noop, strokeText: noop, fillRect: noop, beginPath: noop, closePath: noop,
            arc: noop, arcTo: noop, moveTo: noop, lineTo: noop, clip: noop,
            save: noop, restore: noop, stroke: noop, fill: noop, drawImage: noop,
            createLinearGradient: () => ({ addColorStop: noop }),
            createRadialGradient: () => ({ addColorStop: noop }),
        };
    }

    /** Lignes de classement : la première porte l'accent, comme .rw-row--lead. */
    drawRows(ctx, items, x, y, maxW, accent) {
        for (const [i, item] of items.entries()) {
            const h = 108;
            const lead = i === 0;
            this.roundRect(ctx, x, y, maxW, h, 22);
            ctx.fillStyle = lead ? this.alpha(accent, 0.14) : 'rgba(255,255,255,0.04)';
            ctx.fill();
            ctx.strokeStyle = lead ? this.alpha(accent, 0.34) : 'rgba(255,255,255,0.06)';
            ctx.lineWidth = 2;
            ctx.stroke();

            let tx = x + 30;
            if (item.rank) {
                ctx.fillStyle = lead ? accent : 'rgba(243,244,246,0.32)';
                ctx.font = '700 30px Geist, sans-serif';
                ctx.fillText(String(item.rank), tx, y + h / 2 - 16);
                tx += 46;
            }
            ctx.fillStyle = '#F3F4F6';
            ctx.font = '600 38px Geist, sans-serif';
            ctx.fillText(this.ellipsis(ctx, item.label, maxW - 200), tx, y + h / 2 - 20);

            ctx.fillStyle = 'rgba(243,244,246,0.56)';
            ctx.font = '700 32px Geist, sans-serif';
            ctx.textAlign = 'right';
            ctx.fillText(`×${item.value}`, x + maxW - 30, y + h / 2 - 17);
            ctx.textAlign = 'left';

            y += h + 16;
        }

        return y;
    }

    /** Barres proportionnelles : la plus haute valeur occupe toute la largeur. */
    drawBars(ctx, items, x, y, maxW, accent) {
        const peak = Math.max(...items.map((i) => parseFloat(i.value) || 0), 1);

        for (const item of items) {
            ctx.fillStyle = 'rgba(243,244,246,0.72)';
            ctx.font = '500 32px Geist, sans-serif';
            ctx.fillText(this.ellipsis(ctx, item.label, maxW - 120), x, y);

            ctx.fillStyle = accent;
            ctx.font = '700 30px Geist, sans-serif';
            ctx.textAlign = 'right';
            ctx.fillText(String(item.value), x + maxW, y + 2);
            ctx.textAlign = 'left';
            y += 54;

            this.roundRect(ctx, x, y, maxW, 12, 6);
            ctx.fillStyle = 'rgba(255,255,255,0.06)';
            ctx.fill();

            const w = Math.max(12, (parseFloat(item.value) || 0) / peak * maxW);
            const g = ctx.createLinearGradient(x, y, x + w, y);
            g.addColorStop(0, accent);
            g.addColorStop(1, this.alpha(accent, 0.45));
            this.roundRect(ctx, x, y, w, 12, 6);
            ctx.fillStyle = g;
            ctx.fill();
            y += 58;
        }

        return y;
    }

    roundRect(ctx, x, y, w, h, r) {
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.arcTo(x + w, y, x + w, y + h, r);
        ctx.arcTo(x + w, y + h, x, y + h, r);
        ctx.arcTo(x, y + h, x, y, r);
        ctx.arcTo(x, y, x + w, y, r);
        ctx.closePath();
    }

    /** #RRGGBB + opacité → rgba(). Les couleurs viennent du serveur en hex. */
    alpha(hex, a) {
        const n = parseInt(hex.slice(1), 16);

        return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${a})`;
    }

    glow(ctx, x, y, r, color, strength) {
        const g = ctx.createRadialGradient(x, y, 0, x, y, r);
        g.addColorStop(0, this.alpha(color, strength));
        g.addColorStop(1, this.alpha(color, 0));
        ctx.fillStyle = g;
        ctx.beginPath();
        ctx.arc(x, y, r, 0, Math.PI * 2);
        ctx.fill();
    }

    async ensureFonts() {
        if (!document.fonts) { return; }
        try {
            await Promise.all([
                document.fonts.load('700 150px Geist'),
                document.fonts.load('600 40px Geist'),
                document.fonts.load('500 44px Geist'),
                document.fonts.load('400 34px Geist'),
            ]);
            await document.fonts.ready;
        } catch { /* on dessinera avec la police de repli */ }
    }

    loadImage(src) {
        return new Promise((resolve) => {
            const img = new Image();
            img.onload = () => resolve(img);
            img.onerror = () => resolve(null); // une story sans photo vaut mieux que pas de story
            img.src = src;
        });
    }

    /** Dessine l'image en « cover » : remplit le cadre sans déformer. */
    drawCover(ctx, img, x, y, w, h) {
        const scale = Math.max(w / img.width, h / img.height);
        const sw = w / scale, sh = h / scale;
        ctx.drawImage(img, (img.width - sw) / 2, (img.height - sh) / 2, sw, sh, x, y, w, h);
    }

    /** Retourne le y après le dernier ligne écrite. */
    wrap(ctx, text, x, y, maxWidth, lineHeight) {
        const words = String(text).split(' ');
        let line = '';
        for (const word of words) {
            const test = line ? `${line} ${word}` : word;
            if (ctx.measureText(test).width > maxWidth && line) {
                ctx.fillText(line, x, y);
                y += lineHeight;
                line = word;
            } else {
                line = test;
            }
        }
        if (line) {
            ctx.fillText(line, x, y);
            y += lineHeight;
        }

        return y;
    }

    ellipsis(ctx, text, maxWidth) {
        let s = String(text);
        if (ctx.measureText(s).width <= maxWidth) { return s; }
        while (s.length > 1 && ctx.measureText(`${s}…`).width > maxWidth) { s = s.slice(0, -1); }

        return `${s}…`;
    }

    /**
     * Pose une police qui tient dans `maxWidth`, en réduisant la taille au besoin,
     * et retourne la taille retenue. Un canvas ne rogne pas : sans ça, le texte
     * sortirait simplement du cadre de la story.
     */
    fit(ctx, text, maxWidth, weight, size) {
        let s = size;
        ctx.font = `${weight} ${s}px Geist, sans-serif`;
        while (s > 12 && ctx.measureText(text).width > maxWidth) {
            s -= 2;
            ctx.font = `${weight} ${s}px Geist, sans-serif`;
        }

        return s;
    }

    /** Canvas n'a pas letter-spacing partout : on l'imite pour les libellés courts. */
    spaced(text) {
        return String(text).split('').join(' ');
    }
}
