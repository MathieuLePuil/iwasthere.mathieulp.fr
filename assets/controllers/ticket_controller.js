import { Controller } from '@hotwired/stimulus';

/*
 * Souvenir ticket generator — draws a collector-style ticket on a canvas and
 * lets the user save it to their photo gallery. Concerts get the setlist-era
 * stub, sport events get a scoreboard panel above the perforation.
 */
export default class extends Controller {
    static targets = ['modal', 'preview', 'dots'];
    static values = {
        artist: String,
        tour: String,
        venue: String,
        date: String,
        time: String,
        type: String,
        image: String,
        artistImage: String,
        team1: String,
        team2: String,
        score: String,
        winner: Number,
    };

    W = 1000;
    H = 1750;
    TICKET_X = 70;
    TICKET_Y = 70;
    TICKET_W = 860;
    TICKET_H = 1610;
    SCOREBOARD_H = 250;

    /* Accent + header art per event type. The accents are the same ones the badges
       use in app.css — they are already tuned to stay readable on the dark card. */
    static PALETTE = {
        concert:  { accent: '#B060FF', base: '#061119', tint: 'rgba(7, 51, 74, 0.9)',   emoji: '🎤', label: 'CONCERT' },
        festival: { accent: '#B060FF', base: '#1A0E09', tint: 'rgba(92, 49, 32, 0.9)',  emoji: '🎪', label: 'FESTIVAL' },
        football: { accent: '#7EE8A2', base: '#050912', tint: 'rgba(14, 46, 26, 0.95)', emoji: '⚽', label: 'FOOTBALL' },
        rugby:    { accent: '#B0BC9A', base: '#0A0F03', tint: 'rgba(42, 48, 16, 0.95)', emoji: '🏉', label: 'RUGBY' },
        tennis:   { accent: '#F4D04A', base: '#160808', tint: 'rgba(50, 20, 20, 0.95)', emoji: '🎾', label: 'TENNIS' },
    };

    get palette() {
        return this.constructor.PALETTE[this.typeValue] ?? {
            accent: '#B060FF', base: '#061119', tint: 'rgba(7, 51, 74, 0.9)', emoji: '🎫', label: 'ÉVÉNEMENT',
        };
    }

    /* The scoreboard needs both sides and a score; the winner (1, 2, or 0 when none
       could be crowned) is resolved server-side by Event::getScoreline() so the manual
       override and the score parsing stay decided in one place. */
    get scoreline() {
        if (!this.team1Value || !this.team2Value || !this.scoreValue) return null;
        return {
            team1: this.team1Value,
            team2: this.team2Value,
            score: this.scoreValue,
            winner: this.winnerValue,
        };
    }

    /* "Pas de vainqueur" covers two different things: a real draw (2 - 2) and a score
       nobody could rank — a tennis score reads "6/2 6/2", which getScoreline() can't
       compare, so it falls back to no winner. Only a plain equal score is announced as
       a draw; anything else stays silent rather than calling a tennis match a tie. */
    get isDraw() {
        const parts = /^\s*(\d+)\s*-\s*(\d+)\s*$/.exec(this.scoreValue);
        return !!parts && Number(parts[1]) === Number(parts[2]);
    }

    disconnect() {
        document.body.style.overflow = '';
    }

    /* One ticket variant per available visual: personal photo, artist photo, plain */
    get variants() {
        if (!this._variants) {
            this._variants = [];
            if (this.imageValue) {
                this._variants.push({ kind: 'photo', label: 'Ma photo', src: this.imageValue });
            }
            if (this.artistImageValue) {
                this._variants.push({ kind: 'artist', label: 'Photo artiste', src: this.artistImageValue });
            }
            this._variants.push({ kind: 'plain', label: 'Sans photo', src: null });
        }
        return this._variants;
    }

    async open() {
        await this.show(this.index ?? 0);
        this.modalTarget.classList.remove('hidden');
        this.modalTarget.classList.add('flex');
        document.body.style.overflow = 'hidden';
    }

    close() {
        this.modalTarget.classList.add('hidden');
        this.modalTarget.classList.remove('flex');
        document.body.style.overflow = '';
    }

    /* direction: -1 slides in from the left, 1 from the right, 0 no animation */
    async show(index, direction = 0) {
        if (this.animating) return;
        index = Math.max(0, Math.min(index, this.variants.length - 1));
        if (direction !== 0 && index === this.index) return;
        this.animating = true;

        try {
            const variant = this.variants[index];
            if (!variant.blob) {
                await this.generate(variant);
            }

            const img = this.previewTarget;
            if (direction !== 0) {
                img.style.transition = 'transform 0.16s ease-in, opacity 0.16s ease-in';
                img.style.transform = 'translateX(' + (-direction * 28) + 'px)';
                img.style.opacity = '0';
                await new Promise((resolve) => setTimeout(resolve, 160));
            }

            this.index = index;
            img.src = variant.dataUrl;
            this.renderDots();

            if (direction !== 0) {
                img.style.transition = 'none';
                img.style.transform = 'translateX(' + (direction * 28) + 'px)';
                img.offsetHeight; // reflow so the incoming slide animates
                img.style.transition = 'transform 0.2s ease-out, opacity 0.2s ease-out';
                img.style.transform = 'translateX(0)';
                img.style.opacity = '1';
            }
        } finally {
            this.animating = false;
        }
    }

    prev() {
        if (this.index > 0) this.show(this.index - 1, -1);
    }

    next() {
        if (this.index < this.variants.length - 1) this.show(this.index + 1, 1);
    }

    goTo(event) {
        const target = parseInt(event.currentTarget.dataset.index, 10);
        this.show(target, Math.sign(target - this.index));
    }

    /* Tap on the left/right half of the preview also navigates (desktop) */
    previewClick(event) {
        if (this.variants.length < 2) return;
        const rect = event.currentTarget.getBoundingClientRect();
        (event.clientX - rect.left) < rect.width / 2 ? this.prev() : this.next();
    }

    touchStart(event) {
        const t = event.changedTouches[0];
        this.touch = { x: t.clientX, y: t.clientY };
    }

    touchEnd(event) {
        if (!this.touch) return;
        const t = event.changedTouches[0];
        const dx = t.clientX - this.touch.x;
        const dy = t.clientY - this.touch.y;
        this.touch = null;
        if (Math.abs(dx) < 50 || Math.abs(dx) < Math.abs(dy)) return;
        dx < 0 ? this.next() : this.prev();
    }

    renderDots() {
        if (!this.hasDotsTarget) return;
        if (this.variants.length < 2) {
            this.dotsTarget.innerHTML = '';
            return;
        }
        const dots = this.variants.map((v, i) =>
            '<button type="button" data-action="ticket#goTo" data-index="' + i + '" aria-label="' + v.label + '"'
            + ' style="width:' + (i === this.index ? 22 : 8) + 'px;height:8px;border-radius:4px;border:0;padding:0;cursor:pointer;transition:all .2s;'
            + 'background:' + (i === this.index ? this.palette.accent : 'rgba(243,244,246,0.35)') + '"></button>'
        ).join('');
        this.dotsTarget.innerHTML =
            '<div class="flex items-center justify-center gap-2">' + dots + '</div>'
            + '<p class="text-xs text-center mt-2" style="color:rgba(243,244,246,0.55)">'
            + this.variants[this.index].label + ' \u00b7 swipe pour changer</p>';
    }

    async save() {
        const variant = this.variants[this.index ?? 0];
        if (!variant || !variant.blob) return;

        const slug = (this.artistValue || 'concert')
            .toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
        const filename = 'ticket-' + slug + '-' + (this.dateValue || '').slice(0, 4) + '.png';
        const file = new File([variant.blob], filename, { type: 'image/png' });

        if (navigator.canShare && navigator.canShare({ files: [file] })) {
            try {
                await navigator.share({ files: [file] });
                return;
            } catch (e) {
                if (e.name === 'AbortError') return;
            }
        }

        const url = URL.createObjectURL(variant.blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 2000);
    }

    async generate(variant) {
        const canvas = document.createElement('canvas');
        canvas.width = this.W;
        canvas.height = this.H;
        const ctx = canvas.getContext('2d');

        let image = null;
        if (variant.src) {
            try {
                image = new Image();
                image.src = variant.src;
                await image.decode();
            } catch (e) {
                image = null;
            }
        }

        this.drawBackdrop(ctx);
        this.drawTicketBase(ctx);
        const perforationY = this.TICKET_Y + this.TICKET_H - 420;
        // The scoreboard eats into the body, so the header and title stop above it
        // rather than running under the panel.
        const scoreline = this.scoreline;
        const bodyBottom = perforationY - (scoreline ? this.SCOREBOARD_H : 0);
        if (image) {
            this.drawPhotoHeader(ctx, image, bodyBottom, variant.kind);
            this.drawTitleBlock(ctx, { bottom: bodyBottom - 56 });
        } else {
            const headerBottom = this.drawHeader(ctx);
            this.drawTitleBlock(ctx, { top: headerBottom - 10 });
        }
        if (scoreline) {
            this.drawScoreboard(ctx, scoreline, bodyBottom, perforationY);
        }
        this.drawPerforation(ctx, perforationY);
        this.drawStub(ctx, perforationY);

        variant.blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
        variant.dataUrl = canvas.toDataURL('image/png');
    }

    /* ── Drawing helpers ── */

    font(weight, size, mono = false) {
        const family = mono
            ? '"Geist Mono", ui-monospace, "SF Mono", Menlo, monospace'
            : '"Geist", ui-sans-serif, system-ui, -apple-system, sans-serif';
        return weight + ' ' + size + 'px ' + family;
    }

    /* '#B060FF' + 0.16 → 'rgba(176, 96, 255, 0.16)' — canvas gradients need the alpha
       inline, and the palette accents are plain hex. */
    rgba(hex, alpha) {
        const n = parseInt(hex.slice(1), 16);
        return 'rgba(' + ((n >> 16) & 255) + ', ' + ((n >> 8) & 255) + ', ' + (n & 255) + ', ' + alpha + ')';
    }

    roundRectPath(ctx, x, y, w, h, r) {
        ctx.beginPath();
        if (ctx.roundRect) {
            ctx.roundRect(x, y, w, h, r);
        } else {
            ctx.moveTo(x + r, y);
            ctx.arcTo(x + w, y, x + w, y + h, r);
            ctx.arcTo(x + w, y + h, x, y + h, r);
            ctx.arcTo(x, y + h, x, y, r);
            ctx.arcTo(x, y, x + w, y, r);
            ctx.closePath();
        }
    }

    wrapText(ctx, text, maxWidth, maxLines) {
        const words = String(text).split(/\s+/).filter(Boolean);
        const lines = [];
        let current = '';
        for (const word of words) {
            const attempt = current ? current + ' ' + word : word;
            if (ctx.measureText(attempt).width <= maxWidth || !current) {
                current = attempt;
            } else {
                lines.push(current);
                current = word;
            }
        }
        if (current) lines.push(current);
        if (lines.length > maxLines) {
            const kept = lines.slice(0, maxLines);
            let last = kept[maxLines - 1];
            while (ctx.measureText(last + '…').width > maxWidth && last.length > 1) {
                last = last.slice(0, -1);
            }
            kept[maxLines - 1] = last + '…';
            return kept;
        }
        return lines;
    }

    drawBackdrop(ctx) {
        ctx.fillStyle = '#0B0D10';
        ctx.fillRect(0, 0, this.W, this.H);

        const accent = this.palette.accent;
        const glow = ctx.createRadialGradient(this.W / 2, 0, 0, this.W / 2, 0, 900);
        glow.addColorStop(0, this.rgba(accent, 0.16));
        glow.addColorStop(1, this.rgba(accent, 0));
        ctx.fillStyle = glow;
        ctx.fillRect(0, 0, this.W, 900);
    }

    drawTicketBase(ctx) {
        ctx.save();
        ctx.shadowColor = 'rgba(0, 0, 0, 0.55)';
        ctx.shadowBlur = 70;
        ctx.shadowOffsetY = 24;
        this.roundRectPath(ctx, this.TICKET_X, this.TICKET_Y, this.TICKET_W, this.TICKET_H, 44);
        ctx.fillStyle = '#13161B';
        ctx.fill();
        ctx.restore();

        this.roundRectPath(ctx, this.TICKET_X, this.TICKET_Y, this.TICKET_W, this.TICKET_H, 44);
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.10)';
        ctx.lineWidth = 2;
        ctx.stroke();
    }

    drawPhotoHeader(ctx, image, perforationY, kind) {
        const x = this.TICKET_X;
        const y = this.TICKET_Y;
        const w = this.TICKET_W;
        const h = perforationY - y;

        if (kind === 'artist') {
            // Artist picture: full ticket width at its native 1:1 ratio —
            // never zoomed/cropped — melting into the ticket body below.
            ctx.save();
            this.roundRectPath(ctx, x, y, w, w, [44, 44, 0, 0]);
            ctx.clip();

            const scale = Math.max(w / image.width, w / image.height);
            const dw = image.width * scale;
            const dh = image.height * scale;
            ctx.drawImage(image, x + (w - dw) / 2, y + (w - dh) / 2, dw, dh);

            const fade = ctx.createLinearGradient(0, y + w - 300, 0, y + w);
            fade.addColorStop(0, 'rgba(19, 22, 27, 0)');
            fade.addColorStop(0.6, 'rgba(19, 22, 27, 0.55)');
            fade.addColorStop(1, '#13161B');
            ctx.fillStyle = fade;
            ctx.fillRect(x, y + w - 300, w, 300);

            ctx.restore();
            return;
        }

        ctx.save();
        this.roundRectPath(ctx, x, y, w, h, [44, 44, 0, 0]);
        ctx.clip();

        if (image.width <= image.height * 1.15) {
            // Square or portrait (artist pictures, phone shots): fill the whole
            // header edge to edge, biasing the crop toward the top (faces).
            const scale = Math.max(w / image.width, h / image.height);
            const dw = image.width * scale;
            const dh = image.height * scale;
            ctx.drawImage(image, x + (w - dw) / 2, y + (h - dh) * 0.3, dw, dh);
        } else {
            // Wide landscape photo: cover-cropping would gut it, so show it
            // whole over a blurred echo of itself.
            const cover = Math.max(w / image.width, h / image.height);
            const cw = image.width * cover;
            const ch = image.height * cover;
            ctx.filter = 'blur(60px)';
            ctx.drawImage(image, x + (w - cw) / 2, y + (h - ch) / 2, cw, ch);
            ctx.filter = 'none';
            ctx.fillStyle = 'rgba(11, 13, 16, 0.55)';
            ctx.fillRect(x, y, w, h);

            const scale = Math.min(w / image.width, h / image.height);
            const dw = image.width * scale;
            const dh = image.height * scale;
            ctx.drawImage(image, x + (w - dw) / 2, y + (h - dh) * 0.35, dw, dh);
        }

        // Scrim so the title stays readable over the photo
        const fade = ctx.createLinearGradient(0, perforationY - 560, 0, perforationY);
        fade.addColorStop(0, 'rgba(19, 22, 27, 0)');
        fade.addColorStop(0.55, 'rgba(19, 22, 27, 0.65)');
        fade.addColorStop(1, '#13161B');
        ctx.fillStyle = fade;
        ctx.fillRect(x, perforationY - 560, w, 560);

        ctx.restore();
    }

    drawHeader(ctx) {
        const headerH = 560;
        const x = this.TICKET_X;
        const y = this.TICKET_Y;
        const w = this.TICKET_W;

        ctx.save();
        this.roundRectPath(ctx, x, y, w, headerH, [44, 44, 0, 0]);
        ctx.clip();

        {
            const palette = this.palette;
            const base = ctx.createLinearGradient(x, y, x, y + headerH);
            base.addColorStop(0, palette.base);
            base.addColorStop(1, '#13161B');
            ctx.fillStyle = base;
            ctx.fillRect(x, y, w, headerH);

            const tint = ctx.createRadialGradient(x + w / 2, y + 120, 0, x + w / 2, y + 120, 620);
            tint.addColorStop(0, palette.tint);
            tint.addColorStop(1, 'rgba(0, 0, 0, 0)');
            ctx.fillStyle = tint;
            ctx.fillRect(x, y, w, headerH);

            let seed = 7;
            ctx.fillStyle = 'rgba(243, 244, 246, 0.35)';
            for (let i = 0; i < 26; i++) {
                seed = (seed * 16807) % 2147483647;
                const sx = x + 30 + (seed % (w - 60));
                seed = (seed * 16807) % 2147483647;
                const sy = y + 30 + (seed % (headerH - 160));
                seed = (seed * 16807) % 2147483647;
                ctx.globalAlpha = 0.12 + (seed % 30) / 100;
                ctx.beginPath();
                ctx.arc(sx, sy, 2 + (seed % 3), 0, Math.PI * 2);
                ctx.fill();
            }
            ctx.globalAlpha = 1;

            ctx.font = this.font(400, 150);
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(palette.emoji, x + w / 2, y + headerH / 2 - 30);
        }

        // Fade into the card body
        const fade = ctx.createLinearGradient(0, y + headerH - 240, 0, y + headerH);
        fade.addColorStop(0, 'rgba(19, 22, 27, 0)');
        fade.addColorStop(1, '#13161B');
        ctx.fillStyle = fade;
        ctx.fillRect(x, y + headerH - 240, w, 240);

        ctx.restore();
        return y + headerH;
    }

    drawTitleBlock(ctx, anchor) {
        const x = this.TICKET_X + 70;
        const maxWidth = this.TICKET_W - 140;

        ctx.textAlign = 'left';
        ctx.textBaseline = 'alphabetic';

        let size = 72;
        let lines;
        do {
            ctx.font = this.font(700, size);
            lines = this.wrapText(ctx, this.artistValue || 'Événement', maxWidth, 2);
            if (lines.length <= 2 && lines.every((l) => ctx.measureText(l).width <= maxWidth)) break;
            size -= 4;
        } while (size > 44);

        const blockH = 30 + lines.length * (size + 10) + (this.tourValue ? 52 : 0);
        let y = anchor.bottom !== undefined ? anchor.bottom - blockH : anchor.top;

        const palette = this.palette;
        ctx.font = this.font(600, 26, true);
        try { ctx.letterSpacing = '8px'; } catch (e) { /* older engines */ }
        ctx.fillStyle = palette.accent;
        ctx.fillText('★ ' + palette.label, x, y);
        try { ctx.letterSpacing = '0px'; } catch (e) { /* older engines */ }

        y += 30;
        ctx.font = this.font(700, size);
        ctx.fillStyle = '#F3F4F6';
        for (const line of lines) {
            y += size + 10;
            ctx.fillText(line, x, y);
        }

        if (this.tourValue) {
            y += 52;
            ctx.font = this.font(400, 32);
            ctx.fillStyle = 'rgba(243, 244, 246, 0.72)';
            const tourLines = this.wrapText(ctx, this.tourValue, maxWidth, 1);
            ctx.fillText(tourLines[0], x, y);
        }
    }

    /* Stadium scoreboard: the two sides framing the final score, the winner kept at
       full strength while the beaten side recedes. Sits between the body and the
       perforation, where a concert ticket has nothing. */
    drawScoreboard(ctx, scoreline, top, perforationY) {
        const palette = this.palette;
        const px = this.TICKET_X + 50;
        const pw = this.TICKET_W - 100;
        const pt = top + 10;
        const ph = perforationY - 40 - pt;

        this.roundRectPath(ctx, px, pt, pw, ph, 26);
        ctx.fillStyle = 'rgba(255, 255, 255, 0.04)';
        ctx.fill();
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.09)';
        ctx.lineWidth = 2;
        ctx.stroke();

        ctx.textAlign = 'center';
        ctx.textBaseline = 'alphabetic';

        ctx.font = this.font(600, 22, true);
        try { ctx.letterSpacing = '5px'; } catch (e) { /* older engines */ }
        ctx.fillStyle = this.rgba(palette.accent, 0.85);
        ctx.fillText('RÉSULTAT', this.W / 2, pt + 44);
        try { ctx.letterSpacing = '0px'; } catch (e) { /* older engines */ }

        // Columns are symmetric around the ticket centre: team, score, team.
        const rowY = pt + 118;
        const colW = 222;
        const sides = [
            { name: scoreline.team1, cx: 259, side: 1 },
            { name: scoreline.team2, cx: 741, side: 2 },
        ];

        for (const side of sides) {
            const won = scoreline.winner === side.side;

            // Club names run long ("Paris Saint-Germain"): wrap to two lines, then
            // shrink if a single word still overflows its column.
            let size = 30;
            let lines;
            do {
                ctx.font = this.font(600, size);
                lines = this.wrapText(ctx, side.name, colW, 2);
                if (lines.every((line) => ctx.measureText(line).width <= colW)) break;
                size -= 2;
            } while (size > 18);

            ctx.fillStyle = won || scoreline.winner === 0 ? '#F3F4F6' : 'rgba(243, 244, 246, 0.45)';
            let ty = lines.length > 1 ? rowY - 8 : rowY + 10;
            for (const line of lines) {
                ctx.fillText(line, side.cx, ty);
                ty += size + 8;
            }

            if (won) {
                ctx.font = this.font(600, 18, true);
                try { ctx.letterSpacing = '3px'; } catch (e) { /* older engines */ }
                ctx.fillStyle = palette.accent;
                ctx.fillText('★ VAINQUEUR', side.cx, pt + 172);
                try { ctx.letterSpacing = '0px'; } catch (e) { /* older engines */ }
            }
        }

        // A football score ("2 - 0") is short and carries the panel at full size. Tennis
        // lists every set, tie-breaks included ("7/6 (7-3) 5/7 6/2 1/6 6/4"), which no
        // legible size fits on one line — those wrap instead of spilling over the teams.
        const scoreW = 250;
        let size = 68;
        do {
            ctx.font = this.font(700, size, true);
            if (ctx.measureText(scoreline.score).width <= scoreW) break;
            size -= 2;
        } while (size > 34);

        let scoreLines = [scoreline.score];
        if (ctx.measureText(scoreline.score).width > scoreW) {
            size = 30;
            ctx.font = this.font(700, size, true);
            scoreLines = this.wrapText(ctx, scoreline.score, scoreW, 2);
        }

        ctx.fillStyle = '#F3F4F6';
        const step = size + 6;
        let sy = rowY + 22 - ((scoreLines.length - 1) * step) / 2;
        for (const line of scoreLines) {
            ctx.fillText(line, this.W / 2, sy);
            sy += step;
        }

        if (scoreline.winner === 0 && this.isDraw) {
            ctx.font = this.font(600, 18, true);
            try { ctx.letterSpacing = '3px'; } catch (e) { /* older engines */ }
            ctx.fillStyle = 'rgba(243, 244, 246, 0.48)';
            ctx.fillText('MATCH NUL', this.W / 2, pt + 172);
            try { ctx.letterSpacing = '0px'; } catch (e) { /* older engines */ }
        }

        ctx.textAlign = 'left';
    }

    drawPerforation(ctx, py) {
        const x = this.TICKET_X;
        const w = this.TICKET_W;

        ctx.save();
        ctx.setLineDash([14, 18]);
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.16)';
        ctx.lineWidth = 3;
        ctx.beginPath();
        ctx.moveTo(x + 50, py);
        ctx.lineTo(x + w - 50, py);
        ctx.stroke();
        ctx.restore();

        for (const cx of [x, x + w]) {
            ctx.beginPath();
            ctx.arc(cx, py, 34, 0, Math.PI * 2);
            ctx.fillStyle = '#0B0D10';
            ctx.fill();
            ctx.strokeStyle = 'rgba(255, 255, 255, 0.10)';
            ctx.lineWidth = 2;
            ctx.stroke();
        }
    }

    drawStub(ctx, py) {
        const x = this.TICKET_X + 70;
        const w = this.TICKET_W - 140;
        const colRight = x + w / 2 + 10;

        ctx.textAlign = 'left';
        ctx.textBaseline = 'alphabetic';

        const labelFont = this.font(600, 22, true);
        const setLabel = (text, lx, ly) => {
            ctx.font = labelFont;
            try { ctx.letterSpacing = '5px'; } catch (e) { /* older engines */ }
            ctx.fillStyle = 'rgba(243, 244, 246, 0.48)';
            ctx.fillText(text, lx, ly);
            try { ctx.letterSpacing = '0px'; } catch (e) { /* older engines */ }
        };

        let y = py + 56;
        setLabel('DATE', x, y);
        setLabel('LIEU', colRight, y);

        y += 48;
        let dateText = '—';
        if (this.dateValue) {
            dateText = new Intl.DateTimeFormat('fr-FR', {
                day: 'numeric', month: 'long', year: 'numeric',
            }).format(new Date(this.dateValue + 'T12:00:00'));
        }
        ctx.font = this.font(600, 34);
        ctx.fillStyle = '#F3F4F6';
        ctx.fillText(dateText, x, y);

        if (this.timeValue) {
            ctx.font = this.font(400, 27);
            ctx.fillStyle = 'rgba(243, 244, 246, 0.48)';
            ctx.fillText(this.timeValue.replace(':', 'h'), x, y + 42);
        }

        const venueLines = this.wrapText(ctx, this.venueValue || '—', w / 2 - 20, 2);
        let vy = y;
        for (const line of venueLines) {
            ctx.fillText(line, colRight, vy);
            vy += 42;
        }

        this.drawBarcode(ctx, py + 208);

        ctx.font = this.font(600, 28);
        ctx.fillStyle = 'rgba(243, 244, 246, 0.72)';
        ctx.textAlign = 'center';
        ctx.fillText('IWasThere', this.W / 2, this.TICKET_Y + this.TICKET_H - 56);
        ctx.font = this.font(400, 22);
        ctx.fillStyle = 'rgba(243, 244, 246, 0.38)';
        ctx.fillText('Ticket souvenir', this.W / 2, this.TICKET_Y + this.TICKET_H - 24);
        ctx.textAlign = 'left';
    }

    drawBarcode(ctx, y) {
        const source = (this.artistValue || '') + '|' + (this.dateValue || '') + '|' + (this.venueValue || '');
        let hash = 5381;
        for (let i = 0; i < source.length; i++) {
            hash = ((hash << 5) + hash + source.charCodeAt(i)) & 0x7fffffff;
        }

        const barcodeW = 620;
        const barcodeH = 84;
        let bx = (this.W - barcodeW) / 2;
        const seedNext = () => {
            hash = (hash * 16807) % 2147483647;
            return hash;
        };

        ctx.fillStyle = 'rgba(243, 244, 246, 0.88)';
        const end = bx + barcodeW;
        while (bx < end) {
            const barW = 3 + (seedNext() % 4) * 3;
            const gap = 5 + (seedNext() % 3) * 4;
            ctx.fillRect(bx, y, Math.min(barW, end - bx), barcodeH);
            bx += barW + gap;
        }

        let code = '';
        const chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        for (let i = 0; i < 12; i++) {
            code += chars[seedNext() % chars.length];
            if (i === 3 || i === 7) code += ' ';
        }
        ctx.font = this.font(400, 24, true);
        try { ctx.letterSpacing = '6px'; } catch (e) { /* older engines */ }
        ctx.fillStyle = 'rgba(243, 244, 246, 0.48)';
        ctx.textAlign = 'center';
        ctx.fillText(code, this.W / 2, y + barcodeH + 40);
        try { ctx.letterSpacing = '0px'; } catch (e) { /* older engines */ }
        ctx.textAlign = 'left';
    }
}
