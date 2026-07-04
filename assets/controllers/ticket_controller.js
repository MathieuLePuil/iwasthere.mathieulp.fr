import { Controller } from '@hotwired/stimulus';

/*
 * Souvenir ticket generator — draws a collector-style concert ticket
 * on a canvas and lets the user save it to their photo gallery.
 */
export default class extends Controller {
    static targets = ['modal', 'preview'];
    static values = {
        artist: String,
        tour: String,
        venue: String,
        city: String,
        date: String,
        time: String,
        type: String,
        image: String,
    };

    W = 1000;
    H = 1750;
    TICKET_X = 70;
    TICKET_Y = 70;
    TICKET_W = 860;
    TICKET_H = 1610;

    disconnect() {
        document.body.style.overflow = '';
    }

    async open() {
        if (!this.blob) {
            await this.generate();
        }
        this.modalTarget.classList.remove('hidden');
        this.modalTarget.classList.add('flex');
        document.body.style.overflow = 'hidden';
    }

    close() {
        this.modalTarget.classList.add('hidden');
        this.modalTarget.classList.remove('flex');
        document.body.style.overflow = '';
    }

    async save() {
        if (!this.blob) return;

        const slug = (this.artistValue || 'concert')
            .toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
        const filename = 'ticket-' + slug + '-' + (this.dateValue || '').slice(0, 4) + '.png';
        const file = new File([this.blob], filename, { type: 'image/png' });

        if (navigator.canShare && navigator.canShare({ files: [file] })) {
            try {
                await navigator.share({ files: [file] });
                return;
            } catch (e) {
                if (e.name === 'AbortError') return;
            }
        }

        const url = URL.createObjectURL(this.blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 2000);
    }

    async generate() {
        const canvas = document.createElement('canvas');
        canvas.width = this.W;
        canvas.height = this.H;
        const ctx = canvas.getContext('2d');

        let image = null;
        if (this.imageValue) {
            try {
                image = new Image();
                image.src = this.imageValue;
                await image.decode();
            } catch (e) {
                image = null;
            }
        }

        this.drawBackdrop(ctx);
        this.drawTicketBase(ctx);
        const perforationY = this.TICKET_Y + this.TICKET_H - 420;
        if (image) {
            this.drawPhotoHeader(ctx, image, perforationY);
            this.drawTitleBlock(ctx, { bottom: perforationY - 56 });
        } else {
            const headerBottom = this.drawHeader(ctx);
            this.drawTitleBlock(ctx, { top: headerBottom - 10 });
        }
        this.drawPerforation(ctx, perforationY);
        this.drawStub(ctx, perforationY);

        this.blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
        this.previewTarget.src = canvas.toDataURL('image/png');
    }

    /* ── Drawing helpers ── */

    font(weight, size, mono = false) {
        const family = mono
            ? '"Geist Mono", ui-monospace, "SF Mono", Menlo, monospace'
            : '"Geist", ui-sans-serif, system-ui, -apple-system, sans-serif';
        return weight + ' ' + size + 'px ' + family;
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

        const glow = ctx.createRadialGradient(this.W / 2, 0, 0, this.W / 2, 0, 900);
        glow.addColorStop(0, 'rgba(176, 96, 255, 0.16)');
        glow.addColorStop(1, 'rgba(176, 96, 255, 0)');
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

    drawPhotoHeader(ctx, image, perforationY) {
        const x = this.TICKET_X;
        const y = this.TICKET_Y;
        const w = this.TICKET_W;
        const h = perforationY - y;

        ctx.save();
        this.roundRectPath(ctx, x, y, w, h, [44, 44, 0, 0]);
        ctx.clip();

        // Blurred echo of the photo fills the zone behind the uncropped image
        const cover = Math.max(w / image.width, h / image.height);
        const cw = image.width * cover;
        const ch = image.height * cover;
        ctx.filter = 'blur(60px)';
        ctx.drawImage(image, x + (w - cw) / 2, y + (h - ch) / 2, cw, ch);
        ctx.filter = 'none';
        ctx.fillStyle = 'rgba(11, 13, 16, 0.55)';
        ctx.fillRect(x, y, w, h);

        // Full photo at its own aspect ratio — never cropped or stretched
        const scale = Math.min(w / image.width, h / image.height);
        const dw = image.width * scale;
        const dh = image.height * scale;
        ctx.drawImage(image, x + (w - dw) / 2, y + (h - dh) * 0.35, dw, dh);

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
            const isFestival = this.typeValue === 'festival';
            const base = ctx.createLinearGradient(x, y, x, y + headerH);
            base.addColorStop(0, isFestival ? '#1A0E09' : '#061119');
            base.addColorStop(1, '#13161B');
            ctx.fillStyle = base;
            ctx.fillRect(x, y, w, headerH);

            const tint = ctx.createRadialGradient(x + w / 2, y + 120, 0, x + w / 2, y + 120, 620);
            tint.addColorStop(0, isFestival ? 'rgba(92, 49, 32, 0.9)' : 'rgba(7, 51, 74, 0.9)');
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
            ctx.fillText(isFestival ? '🎪' : '🎤', x + w / 2, y + headerH / 2 - 30);
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

        const label = (this.typeValue === 'festival' ? 'FESTIVAL' : 'CONCERT');
        ctx.font = this.font(600, 26, true);
        try { ctx.letterSpacing = '8px'; } catch (e) { /* older engines */ }
        ctx.fillStyle = '#B060FF';
        ctx.fillText('★ ' + label, x, y);
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
        if (this.cityValue) {
            ctx.font = this.font(400, 27);
            ctx.fillStyle = 'rgba(243, 244, 246, 0.48)';
            ctx.fillText(this.cityValue, colRight, vy);
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
